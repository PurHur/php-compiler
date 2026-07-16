<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\ext\zip\ZipArchiveConstants;
use PHPUnit\Framework\TestCase;

/**
 * ZipArchive VM open/add/extract (issues #6413, #6414, #19492).
 *
 * @group zip_archive
 */
final class ZipArchiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!CompilerVersion::supportsZip()) {
            self::markTestSkipped('ZipArchive withheld on reference profile (#18137); set PHP_COMPILER_PROFILE=8.4');
        }
    }

    public function test_zip_archive_methods_and_constants_registered(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        self::assertTrue(VmReflection::classExists($ctx, 'ZipArchive'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'open'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'close'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'addfile'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'addfromstring'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'getfromname'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'extractto'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'getstatusstring'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'count'));

        $entry = $ctx->classes['ziparchive'];
        self::assertContains('countable', $entry->interfaces);
        self::assertArrayHasKey('create', $entry->constants);
        self::assertSame(ZipArchiveConstants::CREATE, $entry->constants['create']->toInt());
        self::assertSame('CREATE', $entry->constNames['create']);
        self::assertSame('ER_OK', $entry->constNames['er_ok']);
    }

    public function test_zip_archive_create_add_extract_roundtrip(): void
    {
        $tmpdir = sys_get_temp_dir() . '/phpc_zip_' . bin2hex(random_bytes(4));
        mkdir($tmpdir, 0777, true);
        $source = $tmpdir . '/hello.txt';
        file_put_contents($source, 'zip payload');
        $archive = $tmpdir . '/test.zip';
        $extract = $tmpdir . '/out';
        mkdir($extract, 0777, true);

        try {
            $code = <<<'PHP'
<?php
$zipPath = __TMPZIP__;
$source = __SOURCE__;
$extract = __EXTRACT__;
$zip = new ZipArchive();
var_export(method_exists($zip, 'open'));
echo "\n";
$flags = ZipArchive::CREATE | ZipArchive::OVERWRITE;
$opened = $zip->open($zipPath, $flags);
var_export($opened);
echo "\n";
var_export($zip->addFile($source, 'hello.txt'));
echo "\n";
echo (int) $zip->numFiles, "\n";
echo (int) $zip->count(), "\n";
var_export($zip->close());
echo "\n";
$zip2 = new ZipArchive();
var_export($zip2->open($zipPath));
echo "\n";
var_export($zip2->extractTo($extract));
echo "\n";
var_export(file_get_contents($extract . '/hello.txt'));
echo "\n";
var_export($zip2->getStatusString());
echo "\n";
PHP;
            $code = str_replace(
                ['__TMPZIP__', '__SOURCE__', '__EXTRACT__'],
                [var_export($archive, true), var_export($source, true), var_export($extract, true)],
                $code
            );

            $runtime = new Runtime();
            $block = $runtime->parseAndCompile($code, 'zip_roundtrip.php');
            ob_start();
            $runtime->run($block);
            $out = ob_get_clean();

            self::assertSame(
                "true\ntrue\ntrue\n1\n1\ntrue\ntrue\ntrue\n'zip payload'\n'No error'\n",
                $out
            );
        } finally {
            @unlink($source);
            @unlink($archive);
            @unlink($extract . '/hello.txt');
            @rmdir($extract);
            @rmdir($tmpdir);
        }
    }
}
