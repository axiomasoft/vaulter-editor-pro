<?php

declare(strict_types=1);

use Vaulter\EditorPro\Exchange\Configuration\ExchangeOptions;
use Vaulter\EditorPro\Exchange\Exceptions\ExchangeException;
use Vaulter\EditorPro\Exchange\Security\DocxZipArchiveGuard;

/**
 * Build raw ZIP bytes cleanly without writing to disk so arbitrary path names, external attributes
 * (including UNIX symlinks S_IFLNK / special files), and compression ratios can be synthesized directly.
 *
 * @param  list<array{name: string, data: string, ext_attrs?: int, comp_size?: int, uncomp_size?: int, flags?: int}>  $entries
 */
function buildSyntheticDocxZip(array $entries): string
{
    $parts = [];
    $central = [];
    $offset = 0;

    foreach ($entries as $entry) {
        $name = $entry['name'];
        $data = $entry['data'];
        $crc = crc32($data) & 0xFFFFFFFF;
        $compressed = gzdeflate($data, 6);
        if (! is_string($compressed)) {
            throw new RuntimeException('Failed to deflate synthetic ZIP entry.');
        }

        $compLen = $entry['comp_size'] ?? strlen($compressed);
        $uncompLen = $entry['uncomp_size'] ?? strlen($data);
        $extAttrs = $entry['ext_attrs'] ?? (0100644 << 16);
        $nameLen = strlen($name);
        $flags = $entry['flags'] ?? 0;

        $lfh = pack('VvvvvvVVVvv', 0x04034B50, 20, $flags, 8, 0, 0, $crc, $compLen, $uncompLen, $nameLen, 0).$name;
        $cd = pack('VvvvvvvVVVvvvvvVV', 0x02014B50, (3 << 8) | 20, 20, $flags, 8, 0, 0, $crc, $compLen, $uncompLen, $nameLen, 0, 0, 0, 0, $extAttrs, $offset).$name;

        $parts[] = $lfh.$compressed;
        $central[] = $cd;
        $offset += strlen($lfh) + strlen($compressed);
    }

    $cdBuf = implode('', $central);
    $eocd = pack('VvvvvVVv', 0x06054B50, 0, 0, count($entries), count($entries), strlen($cdBuf), $offset, 0);

    return implode('', $parts).$cdBuf.$eocd;
}

function validMinimalDocxZip(?string $documentXml = null, ?string $relsXml = null): string
{
    $doc = $documentXml ?? '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Hello</w:t></w:r></w:p></w:body></w:document>';
    $rels = $relsXml ?? '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://example.test/docs" TargetMode="External"/><Relationship Id="r2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="mailto:legal@example.test" TargetMode="External"/></Relationships>';

    return buildSyntheticDocxZip([
        ['name' => '[Content_Types].xml', 'data' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>'],
        ['name' => 'word/document.xml', 'data' => $doc],
        ['name' => 'word/_rels/document.xml.rels', 'data' => $rels],
    ]);
}

it('accepts valid OOXML DOCX archives with external http/https/mailto hyperlinks only', function (): void {
    $guard = app(DocxZipArchiveGuard::class);
    $guard->assertSafe(validMinimalDocxZip());
    expect(true)->toBeTrue();
});

it('rejects corrupt archives, Zip Slip traversal, leading slash, Windows drive, backslash, and duplicate entries', function (): void {
    $guard = app(DocxZipArchiveGuard::class);
    $ok = '<w:document/>';

    expect(fn () => $guard->assertSafe('not-a-zip'))
        ->toThrow(ExchangeException::class)
        ->and(fn () => $guard->assertSafe('PK'.str_repeat("\0", 30)))
        ->toThrow(ExchangeException::class)
        ->and(fn () => $guard->assertSafe(buildSyntheticDocxZip([['name' => '../evil.xml', 'data' => $ok]])))
        ->toThrow(ExchangeException::class, 'zip_slip')
        ->and(fn () => $guard->assertSafe(buildSyntheticDocxZip([['name' => 'word/../../evil.xml', 'data' => $ok]])))
        ->toThrow(ExchangeException::class, 'zip_slip')
        ->and(fn () => $guard->assertSafe(buildSyntheticDocxZip([['name' => '/word/document.xml', 'data' => $ok]])))
        ->toThrow(ExchangeException::class, 'zip_slip')
        ->and(fn () => $guard->assertSafe(buildSyntheticDocxZip([['name' => 'C:word/document.xml', 'data' => $ok]])))
        ->toThrow(ExchangeException::class, 'zip_slip')
        ->and(fn () => $guard->assertSafe(buildSyntheticDocxZip([['name' => 'word\\document.xml', 'data' => $ok]])))
        ->toThrow(ExchangeException::class, 'zip_slip')
        ->and(fn () => $guard->assertSafe(buildSyntheticDocxZip([
            ['name' => 'word/document.xml', 'data' => $ok],
            ['name' => 'Word/Document.xml', 'data' => $ok],
        ])))
        ->toThrow(ExchangeException::class, 'duplicate_entry_name');
});

it('rejects UNIX symlinks S_IFLNK, special files, zip-bombs, XXE/XInclude, and disallowed external relationships before stream reads', function (): void {
    $guard = new DocxZipArchiveGuard(new ExchangeOptions(
        zipMaxEntries: 10,
        zipMaxEntryBytes: 64 * 1024,
        zipMaxTotalBytes: 128 * 1024,
        zipMaxRatio: 20,
    ));

    // S_IFLNK symlink
    expect(fn () => $guard->assertSafe(buildSyntheticDocxZip([
        ['name' => 'word/document.xml', 'data' => '/etc/passwd', 'ext_attrs' => 0120777 << 16],
    ])))->toThrow(ExchangeException::class, 'zip_symlink');

    // Special FIFO file
    expect(fn () => $guard->assertSafe(buildSyntheticDocxZip([
        ['name' => 'word/document.xml', 'data' => '', 'ext_attrs' => 0010644 << 16],
    ])))->toThrow(ExchangeException::class, 'zip_special_file');

    // Compression ratio zip-bomb
    expect(fn () => $guard->assertSafe(buildSyntheticDocxZip([
        ['name' => '[Content_Types].xml', 'data' => '<Types/>'],
        ['name' => 'word/document.xml', 'data' => str_repeat('A', 32 * 1024)],
    ])))->toThrow(ExchangeException::class, 'compression_ratio_limit');

    // Too many entries
    $many = [];
    for ($i = 0; $i < 12; $i++) {
        $many[] = ['name' => "word/part{$i}.xml", 'data' => '<p/>'];
    }
    expect(fn () => $guard->assertSafe(buildSyntheticDocxZip($many)))
        ->toThrow(ExchangeException::class, 'too_many_entries');

    // XXE DOCTYPE / ENTITY / XInclude
    expect(fn () => $guard->assertSafe(validMinimalDocxZip(
        documentXml: '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><w:document/>',
    )))->toThrow(ExchangeException::class, 'xxe_or_xinclude');

    expect(fn () => $guard->assertSafe(validMinimalDocxZip(
        documentXml: '<?xml version="1.0"?><w:document xmlns:xi="http://www.w3.org/2001/XInclude"><xi:include href="file:///etc/passwd"/></w:document>',
    )))->toThrow(ExchangeException::class, 'xxe_or_xinclude');

    // Disallowed external relationships (attachedTemplate, UNC path, javascript:)
    expect(fn () => $guard->assertSafe(validMinimalDocxZip(
        relsXml: '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/attachedTemplate" Target="https://evil.test/template.dotm" TargetMode="External"/></Relationships>',
    )))->toThrow(ExchangeException::class, 'disallowed_external_relationship');

    expect(fn () => $guard->assertSafe(validMinimalDocxZip(
        relsXml: '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="javascript:alert(1)" TargetMode="External"/></Relationships>',
    )))->toThrow(ExchangeException::class, 'disallowed_external_relationship');

    // Zero stream reads when late entry fails central directory check
    $archive = new class extends ZipArchive
    {
        public int $streamsOpened = 0;

        public function getStreamIndex(int $index, int $flags = 0)
        {
            $this->streamsOpened++;

            return parent::getStreamIndex($index, $flags);
        }
    };
    $tmp = tempnam(sys_get_temp_dir(), 'vedx-stream-');
    expect($tmp)->toBeString();
    file_put_contents($tmp, buildSyntheticDocxZip([
        ['name' => 'word/document.xml', 'data' => '<w:document/>'],
        ['name' => '../bad.xml', 'data' => '<bad/>'],
    ]));

    try {
        expect($archive->open($tmp, ZipArchive::RDONLY))->toBeTrue();
        try {
            $inspect = new ReflectionMethod(DocxZipArchiveGuard::class, 'inspectArchive');
            expect(fn () => $inspect->invoke($guard, $archive))
                ->toThrow(ExchangeException::class, 'zip_slip')
                ->and($archive->streamsOpened)->toBe(0);
        } finally {
            $archive->close();
        }
    } finally {
        @unlink($tmp);
    }
});

it('measures binary parts without holding their inflated bytes in memory', function (): void {
    // 32 MiB of incompressible media: the guard must inflate it to check the real size,
    // but only XML and relationship parts are kept for inspection.
    $media = random_bytes(32 * 1024 * 1024);
    $zip = buildSyntheticDocxZip([
        ['name' => '[Content_Types].xml', 'data' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>'],
        ['name' => 'word/document.xml', 'data' => '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>'],
        ['name' => 'word/media/image1.png', 'data' => $media],
    ]);
    unset($media);
    $guard = app(DocxZipArchiveGuard::class);

    memory_reset_peak_usage();
    $before = memory_get_usage();
    $guard->assertSafe($zip);

    expect(memory_get_peak_usage() - $before)->toBeLessThan(8 * 1024 * 1024);
});

/**
 * A minimal valid package plus `$extra` entries, so each case below fails on exactly one guard.
 *
 * @param  list<array{name: string, data: string, ext_attrs?: int, comp_size?: int, uncomp_size?: int, flags?: int}>  $extra
 */
function docxZipWith(array $extra, bool $withContentTypes = true): string
{
    $base = [
        ['name' => 'word/document.xml', 'data' => '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>'],
    ];
    if ($withContentTypes) {
        array_unshift($base, ['name' => '[Content_Types].xml', 'data' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>']);
    }

    return buildSyntheticDocxZip([...$base, ...$extra]);
}

it('rejects each malformed or oversized entry with its own reason', function (string $zip, string $reason): void {
    $guard = new DocxZipArchiveGuard(new ExchangeOptions(
        zipMaxEntries: 10,
        zipMaxEntryBytes: 64 * 1024,
        zipMaxTotalBytes: 96 * 1024,
        zipMaxRatio: 1_000,
        zipMaxRelsBytes: 1024,
    ));

    expect(fn () => $guard->assertSafe($zip))->toThrow(ExchangeException::class, $reason);
})->with([
    'no entries' => [buildSyntheticDocxZip([]), 'empty_archive'],
    'no [Content_Types].xml' => [docxZipWith([], withContentTypes: false), 'missing_ooxml_parts'],
    'no main document' => [buildSyntheticDocxZip([['name' => '[Content_Types].xml', 'data' => '<Types/>']]), 'missing_ooxml_parts'],
    'entry name not UTF-8' => [docxZipWith([['name' => "word/\xC3\x28.xml", 'data' => '<p/>']]), 'invalid_entry_name'],
    'encrypted entry' => [docxZipWith([['name' => 'word/secret.xml', 'data' => '<p/>', 'flags' => 1]]), 'encrypted_archive'],
    'directory entry with data' => [docxZipWith([['name' => 'word/media/', 'data' => 'payload']]), 'non_empty_directory_entry'],
    'entry over the per-entry cap' => [docxZipWith([['name' => 'word/media/a.bin', 'data' => random_bytes(65 * 1024)]]), 'entry_size_limit'],
    'entries over the total cap' => [docxZipWith([
        ['name' => 'word/media/a.bin', 'data' => random_bytes(60 * 1024)],
        ['name' => 'word/media/b.bin', 'data' => random_bytes(60 * 1024)],
    ]), 'total_size_limit'],
    'relationships part over its cap' => [docxZipWith([['name' => 'word/_rels/document.xml.rels', 'data' => '<Relationships>'.str_repeat(' ', 2048).'</Relationships>']]), 'oversized_rels'],
    'inflates past its declared size' => [docxZipWith([['name' => 'word/media/a.bin', 'data' => random_bytes(4096), 'uncomp_size' => 1024]]), 'zip_bomb_overflow'],
    'XML part not UTF-8' => [docxZipWith([['name' => 'word/styles.xml', 'data' => "<w:styles>\xFF</w:styles>"]]), 'invalid_xml_encoding'],
    'XML part not well-formed' => [docxZipWith([['name' => 'word/styles.xml', 'data' => '<w:styles><w:style></w:styles>']]), 'malformed_xml'],
    // libxml stops at 256 levels on its own, so a part this deep fails as malformed before the
    // guard's own depth check can see it; either way it never reaches the converter.
    'XML nested past the depth cap' => [docxZipWith([['name' => 'word/styles.xml', 'data' => str_repeat('<a>', DocxZipArchiveGuard::MAX_XML_DEPTH + 2).str_repeat('</a>', DocxZipArchiveGuard::MAX_XML_DEPTH + 2)]]), 'malformed_xml'],
    'relationships not well-formed' => [docxZipWith([['name' => 'word/_rels/document.xml.rels', 'data' => '<Relationships><Relationship Id="r1"></Relationships>']]), 'malformed_rels'],
]);

it('accepts empty directory entries and internal relationships', function (): void {
    $guard = app(DocxZipArchiveGuard::class);

    $guard->assertSafe(docxZipWith([
        ['name' => 'word/media/', 'data' => '', 'ext_attrs' => 0040755 << 16],
        ['name' => 'word/_rels/document.xml.rels', 'data' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>'],
    ]));

    expect(true)->toBeTrue();
});
