<?php

declare(strict_types=1);

use Vaulter\EditorPro\Exchange\Configuration\ExchangeOptions;
use Vaulter\EditorPro\Exchange\Exceptions\ExchangeException;
use Vaulter\EditorPro\Exchange\Security\DocxZipArchiveGuard;

/**
 * Build raw ZIP bytes cleanly without writing to disk so arbitrary path names, external attributes
 * (including UNIX symlinks S_IFLNK / special files), and compression ratios can be synthesized directly.
 *
 * @param  list<array{name: string, data: string, ext_attrs?: int, comp_size?: int, uncomp_size?: int}>  $entries
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

        $lfh = pack('VvvvvvVVVvv', 0x04034B50, 20, 0, 8, 0, 0, $crc, $compLen, strlen($data), $nameLen, 0).$name;
        $cd = pack('VvvvvvvVVVvvvvvVV', 0x02014B50, (3 << 8) | 20, 20, 0, 8, 0, 0, $crc, $compLen, $uncompLen, $nameLen, 0, 0, 0, 0, $extAttrs, $offset).$name;

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
