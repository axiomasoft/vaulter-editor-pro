<?php

declare(strict_types=1);

namespace Vaulter\EditorPro\Exchange\Security;

use Packman\Attributes\Api;
use Vaulter\Documents\Content\TipTap\LinkSchemeValidator;
use Vaulter\EditorPro\Exchange\Configuration\ExchangeOptions;
use Vaulter\EditorPro\Exchange\Exceptions\ExchangeException;
use XMLReader;
use ZipArchive;

/**
 * Strict pre-parse OOXML/DOCX ZIP archive inspector (`ZipCentralDirectoryGuard`).
 *
 * Inspects central directory paths, case-insensitive duplicates, UNIX external file attributes
 * (refusing symlinks `S_IFLNK` and special files), size/entry-count/compression-ratio quotas,
 * XML DTD/ENTITY/XInclude payloads, and relationship external targets BEFORE any extraction
 * or runtime conversion, without bulk extraction to disk or legacy URL parsing.
 */
#[Api(since: '1.0')]
final readonly class DocxZipArchiveGuard
{
    public const int MAX_XML_DEPTH = 256;

    private const int S_IFMT = 0170000;

    private const int S_IFREG = 0100000;

    private const int S_IFDIR = 0040000;

    private const int S_IFLNK = 0120000;

    private const string XINCLUDE_NS = 'http://www.w3.org/2001/XInclude';

    private const array HYPERLINK_REL_TYPES = [
        'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink',
        'http://purl.oclc.org/ooxml/officeDocument/relationships/hyperlink',
    ];

    public function __construct(private ExchangeOptions $options) {}

    public function assertSafe(string $bytes): void
    {
        if (strlen($bytes) < 22) {
            throw ExchangeException::unsafeArchive('not_a_zip');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'vedx_');
        if ($tmp === false) {
            throw ExchangeException::runtimeFailed('temp_file_failed');
        }

        try {
            if (file_put_contents($tmp, $bytes) !== strlen($bytes)) {
                throw ExchangeException::runtimeFailed('temp_write_failed');
            }

            $zip = new ZipArchive;
            $opened = $zip->open($tmp, ZipArchive::RDONLY | ZipArchive::CHECKCONS);
            if ($opened !== true) {
                throw ExchangeException::unsafeArchive('invalid_zip');
            }

            try {
                $this->inspectArchive($zip);
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($tmp);
        }
    }

    private function inspectArchive(ZipArchive $zip): void
    {
        $count = $zip->numFiles;
        if ($count < 1) {
            throw ExchangeException::unsafeArchive('empty_archive');
        }
        if ($count > $this->options->zipMaxEntries) {
            throw ExchangeException::zipLimitExceeded('too_many_entries');
        }

        $seen = [];
        $entries = [];
        $declaredTotal = 0;
        $hasContentTypes = false;
        $hasMainDocument = false;

        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i, ZipArchive::FL_ENC_RAW);
            if (! is_array($stat)) {
                throw ExchangeException::unsafeArchive('invalid_entry_header');
            }

            $rawName = $stat['name'];
            $this->assertSafeName($rawName, $seen);
            $this->assertSafeExternalAttrs($zip, $i, $rawName);

            $encryption = $stat['encryption_method'];
            if ($encryption !== ZipArchive::EM_NONE) {
                throw ExchangeException::unsafeArchive('encrypted_archive', $rawName);
            }

            $size = $stat['size'];
            $compSize = $stat['comp_size'];
            if ($size < 0 || $compSize < 0) {
                throw ExchangeException::unsafeArchive('invalid_entry_size', $rawName);
            }
            if ($size > $this->options->zipMaxEntryBytes) {
                throw ExchangeException::zipLimitExceeded('entry_size_limit', $rawName);
            }

            if (str_ends_with($rawName, '/')) {
                if ($size > 0) {
                    throw ExchangeException::unsafeArchive('non_empty_directory_entry', $rawName);
                }

                continue;
            }

            $declaredTotal += $size;
            if ($declaredTotal > $this->options->zipMaxTotalBytes) {
                throw ExchangeException::zipLimitExceeded('total_size_limit');
            }

            if ($size > 0 && ($compSize === 0 || ($size / $compSize) > $this->options->zipMaxRatio)) {
                throw ExchangeException::zipLimitExceeded('compression_ratio_limit', $rawName);
            }

            $lower = strtolower($rawName);
            if ($lower === '[content_types].xml') {
                $hasContentTypes = true;
            } elseif ($lower === 'word/document.xml') {
                $hasMainDocument = true;
            }

            $isRels = str_ends_with($lower, '.rels');
            $isXml = str_ends_with($lower, '.xml');
            if ($isRels && $size > $this->options->zipMaxRelsBytes) {
                throw ExchangeException::unsafeArchive('oversized_rels', $rawName);
            }

            $entries[$i] = [$rawName, $size, $isRels, $isXml];
        }

        $actualTotal = 0;
        foreach ($entries as $i => [$rawName, $size, $isRels, $isXml]) {
            $remaining = $this->options->zipMaxTotalBytes - $actualTotal;
            // Media and other binary parts are only measured: their bytes are never inspected here.
            $content = $this->readBoundedEntry($zip, $i, $rawName, $size, $remaining, keep: $isRels || $isXml);
            $actualTotal += $size;

            if ($isRels) {
                $this->assertSafeXmlText($content, $rawName);
                $this->assertSafeRelationships($content, $rawName);
            } elseif ($isXml) {
                $this->assertSafeXmlText($content, $rawName);
                $this->assertSafeXmlStructure($content, $rawName);
            }
        }

        if (! $hasContentTypes || ! $hasMainDocument) {
            throw ExchangeException::unsafeArchive('missing_ooxml_parts');
        }
    }

    /**
     * @param  array<string, true>  $seen
     */
    private function assertSafeName(string $name, array &$seen): void
    {
        if ($name === '' || str_contains($name, "\0") || ! mb_check_encoding($name, 'UTF-8')) {
            throw ExchangeException::unsafeArchive('invalid_entry_name');
        }
        if (str_starts_with($name, '/')
            || str_contains($name, '\\')
            || preg_match('/^[A-Za-z]:/', $name) === 1
            || preg_match('#(?:^|/)\.\.?(?:/|$)#', rtrim($name, '/')) === 1) {
            throw ExchangeException::unsafeArchive('zip_slip', $name);
        }

        $key = strtolower(rtrim($name, '/'));
        if ($key === '' || isset($seen[$key])) {
            throw ExchangeException::unsafeArchive('duplicate_entry_name', $name);
        }
        $seen[$key] = true;
    }

    private function assertSafeExternalAttrs(ZipArchive $zip, int $index, string $name): void
    {
        $opsys = 0;
        $attr = 0;
        if (! $zip->getExternalAttributesIndex($index, $opsys, $attr) || ! is_int($attr)) {
            return;
        }
        $mode = ($attr >> 16) & self::S_IFMT;
        if ($mode === self::S_IFLNK) {
            throw ExchangeException::unsafeArchive('zip_symlink', $name);
        }
        if ($mode !== 0 && $mode !== self::S_IFREG && $mode !== self::S_IFDIR) {
            throw ExchangeException::unsafeArchive('zip_special_file', $name);
        }
    }

    /**
     * Inflates one entry under the declared-size, per-entry and remaining-total caps. Returns its
     * bytes when $keep is true; otherwise only counts them and returns ''.
     */
    private function readBoundedEntry(ZipArchive $zip, int $index, string $name, int $expectedSize, int $remainingTotal, bool $keep): string
    {
        $stream = $zip->getStreamIndex($index);
        if (! is_resource($stream)) {
            throw ExchangeException::unsafeArchive('unreadable_entry', $name);
        }

        $buf = '';
        $received = 0;
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 65_536);
                if ($chunk === false) {
                    throw ExchangeException::unsafeArchive('corrupt_stream', $name);
                }
                if ($chunk === '') {
                    continue;
                }
                $received += strlen($chunk);
                if ($received > $expectedSize || $received > $this->options->zipMaxEntryBytes || $received > $remainingTotal) {
                    throw ExchangeException::zipLimitExceeded('zip_bomb_overflow', $name);
                }
                if ($keep) {
                    $buf .= $chunk;
                }
            }
        } finally {
            fclose($stream);
        }

        if ($received !== $expectedSize) {
            throw ExchangeException::unsafeArchive('size_mismatch', $name);
        }

        return $buf;
    }

    private function assertSafeXmlText(string $xml, string $entry): void
    {
        if (str_contains($xml, "\0") || ! mb_check_encoding($xml, 'UTF-8')) {
            throw ExchangeException::unsafeArchive('invalid_xml_encoding', $entry);
        }
        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY|ELEMENT|ATTLIST)\b|<[A-Za-z0-9_-]*:include\b/i', $xml) === 1
            || str_contains($xml, self::XINCLUDE_NS)) {
            throw ExchangeException::unsafeArchive('xxe_or_xinclude', $entry);
        }
    }

    private function assertSafeXmlStructure(string $xml, string $entry): void
    {
        $prevInternal = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = @XMLReader::XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        try {
            if (! $reader instanceof XMLReader) {
                throw ExchangeException::unsafeArchive('malformed_xml', $entry);
            }
            while ($reader->read()) {
                if (in_array($reader->nodeType, [XMLReader::DOC_TYPE, XMLReader::ENTITY, XMLReader::ENTITY_REF], true)) {
                    throw ExchangeException::unsafeArchive('xxe_doctype', $entry);
                }
                if ($reader->depth > self::MAX_XML_DEPTH) {
                    throw ExchangeException::unsafeArchive('xml_depth_exceeded', $entry);
                }
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->namespaceURI === self::XINCLUDE_NS) {
                    throw ExchangeException::unsafeArchive('xxe_xinclude', $entry);
                }
            }
            if (libxml_get_last_error() !== false) {
                throw ExchangeException::unsafeArchive('malformed_xml', $entry);
            }
        } finally {
            if ($reader instanceof XMLReader) {
                $reader->close();
            }
            libxml_clear_errors();
            libxml_use_internal_errors($prevInternal);
        }
    }

    private function assertSafeRelationships(string $xml, string $entry): void
    {
        $prevInternal = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = @XMLReader::XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        try {
            if (! $reader instanceof XMLReader) {
                throw ExchangeException::unsafeArchive('malformed_rels', $entry);
            }
            while ($reader->read()) {
                if (in_array($reader->nodeType, [XMLReader::DOC_TYPE, XMLReader::ENTITY, XMLReader::ENTITY_REF], true)) {
                    throw ExchangeException::unsafeArchive('xxe_doctype', $entry);
                }
                if ($reader->depth > self::MAX_XML_DEPTH) {
                    throw ExchangeException::unsafeArchive('xml_depth_exceeded', $entry);
                }
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }
                if ($reader->namespaceURI === self::XINCLUDE_NS) {
                    throw ExchangeException::unsafeArchive('xxe_xinclude', $entry);
                }
                if ($reader->localName !== 'Relationship') {
                    continue;
                }
                $targetMode = $reader->getAttribute('TargetMode') ?? '';
                if (strtolower($targetMode) !== 'external') {
                    continue;
                }

                $relType = $reader->getAttribute('Type') ?? '';
                $target = $reader->getAttribute('Target') ?? '';
                if (! in_array($relType, self::HYPERLINK_REL_TYPES, true) || ! LinkSchemeValidator::isAllowed($target)) {
                    throw ExchangeException::unsafeArchive('disallowed_external_relationship', $entry);
                }
            }
            if (libxml_get_last_error() !== false) {
                throw ExchangeException::unsafeArchive('malformed_rels', $entry);
            }
        } finally {
            if ($reader instanceof XMLReader) {
                $reader->close();
            }
            libxml_clear_errors();
            libxml_use_internal_errors($prevInternal);
        }
    }
}
