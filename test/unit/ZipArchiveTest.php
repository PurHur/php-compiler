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
    private ?string $zipEnablePrev = null;

    protected function setUp(): void
    {
        parent::setUp();
        $prev = getenv('PHP_COMPILER_ENABLE_ZIP');
        $this->zipEnablePrev = false === $prev ? null : $prev;
        putenv('PHP_COMPILER_ENABLE_ZIP=1');
        if (!\PHPCompiler\ext\zip\ZipExtensionPolicy::advertisesExtension()) {
            self::markTestSkipped('ZipArchive withheld (#18137/#25010)');
        }
    }

    protected function tearDown(): void
    {
        if (null === $this->zipEnablePrev) {
            putenv('PHP_COMPILER_ENABLE_ZIP');
        } else {
            putenv('PHP_COMPILER_ENABLE_ZIP='.$this->zipEnablePrev);
        }
        parent::tearDown();
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
        // Index / mutation APIs (#19880)
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'statindex'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'locatename'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'getfromindex'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'getnameindex'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'deletename'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'deleteindex'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'addemptydir'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'renamename'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'renameindex'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'getstream'));
        // mtime / external attributes / compression (#20363)
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'setmtimename'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'setmtimeindex'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'setexternalattributesname'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'setexternalattributesindex'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'getexternalattributesname'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'getexternalattributesindex'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'setcompressionname'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'setcompressionindex'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'iscompressionmethodsupported'));
        // encryption / callbacks / streams / clearError (#20378)
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'isencryptionmethodsupported'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'registerprogresscallback'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'registercancelcallback'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'getstreamindex'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'getstreamname'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'clearerror'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'setencryptionindex'));
        // entry / archive comments (#20386)
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'setcommentname'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'setcommentindex'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'getcommentname'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'getcommentindex'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'setarchivecomment'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'getarchivecomment'));
        // unchange / replace / bulk-add (#20387)
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'unchangeall'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'unchangearchive'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'unchangeindex'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'unchangename'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'replacefile'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'addglob'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'addpattern'));
        // archive flags (#21831)
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'setarchiveflag'));
        self::assertTrue(VmReflection::methodExistsOnClass($ctx->classes['ziparchive'], 'getarchiveflag'));

        $entry = $ctx->classes['ziparchive'];
        self::assertContains('countable', $entry->interfaces);
        // Storage keys are declared UPPER_SNAKE casing (#25929 / #28110).
        self::assertArrayHasKey('CREATE', $entry->constants);
        self::assertSame(ZipArchiveConstants::CREATE, $entry->constants['CREATE']->toInt());
        self::assertArrayHasKey('LENGTH_TO_END', $entry->constants);
        self::assertSame(ZipArchiveConstants::LENGTH_TO_END, $entry->constants['LENGTH_TO_END']->toInt());
        self::assertArrayHasKey('LENGTH_UNCHECKED', $entry->constants);
        self::assertSame(ZipArchiveConstants::LENGTH_UNCHECKED, $entry->constants['LENGTH_UNCHECKED']->toInt());
        self::assertArrayHasKey('FL_OPEN_FILE_NOW', $entry->constants);
        self::assertSame(ZipArchiveConstants::FL_OPEN_FILE_NOW, $entry->constants['FL_OPEN_FILE_NOW']->toInt());
        self::assertArrayHasKey('FL_UNCHANGED', $entry->constants);
        self::assertSame(ZipArchiveConstants::FL_UNCHANGED, $entry->constants['FL_UNCHANGED']->toInt());
        self::assertArrayHasKey('AFL_RDONLY', $entry->constants);
        self::assertSame(ZipArchiveConstants::AFL_RDONLY, $entry->constants['AFL_RDONLY']->toInt());
        self::assertArrayHasKey('AFL_WANT_TORRENTZIP', $entry->constants);
        self::assertSame(ZipArchiveConstants::AFL_WANT_TORRENTZIP, $entry->constants['AFL_WANT_TORRENTZIP']->toInt());
        self::assertArrayHasKey('ER_DATA_LENGTH', $entry->constants);
        self::assertSame(ZipArchiveConstants::ER_DATA_LENGTH, $entry->constants['ER_DATA_LENGTH']->toInt());
        self::assertArrayHasKey('ER_TRUNCATED_ZIP', $entry->constants);
        self::assertSame(ZipArchiveConstants::ER_TRUNCATED_ZIP, $entry->constants['ER_TRUNCATED_ZIP']->toInt());
        self::assertArrayHasKey('LIBZIP_VERSION', $entry->constants);
        self::assertSame(ZipArchiveConstants::LIBZIP_VERSION, $entry->constants['LIBZIP_VERSION']->toString());
        self::assertSame('CREATE', $entry->constNames['CREATE']);
        self::assertSame('LENGTH_UNCHECKED', $entry->constNames['LENGTH_UNCHECKED']);
        self::assertSame('LIBZIP_VERSION', $entry->constNames['LIBZIP_VERSION']);
        self::assertSame('ER_OK', $entry->constNames['ER_OK']);
        self::assertSame(ZipArchiveConstants::CM_STORE, $entry->constants['CM_STORE']->toInt());
        self::assertSame(ZipArchiveConstants::OPSYS_UNIX, $entry->constants['OPSYS_UNIX']->toInt());
        self::assertSame('Unexpected length of data', ZipArchiveConstants::statusString(ZipArchiveConstants::ER_DATA_LENGTH));
        self::assertSame('Possibly truncated or corrupted zip archive', ZipArchiveConstants::statusString(ZipArchiveConstants::ER_TRUNCATED_ZIP));
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
