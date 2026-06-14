<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\session\SessionFileStorage;
use PHPCompiler\ext\standard\VmIni;
use PHPCompiler\ext\standard\VmSession;
use PHPUnit\Framework\TestCase;

/** VmSession GC + file I/O without host Zend delegation (#8072, #6006, #8514). */
final class VmSessionRuntimeShrinkTest extends TestCase
{
    private ?string $savedSessionDir = null;

    protected function setUp(): void
    {
        $dir = getenv('PHP_COMPILER_SESSION_DIR');
        $this->savedSessionDir = false !== $dir ? $dir : null;
        VmSession::reset();
    }

    protected function tearDown(): void
    {
        VmSession::reset();
        $runtime = new Runtime();
        VmIni::restore($runtime->vmContext, 'session.gc_maxlifetime');
        if (false === $this->savedSessionDir) {
            putenv('PHP_COMPILER_SESSION_DIR');
        } else {
            putenv('PHP_COMPILER_SESSION_DIR='.$this->savedSessionDir);
        }
        parent::tearDown();
    }

    public function testLoadSessionDoesNotReferenceHostUnserialize(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmSession.php');
        $this->assertStringContainsString('VmUnserializeFormat::decodePayload', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\unserialize\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/(?<!\\\\)unserialize\\s*\\(/', $source);
    }

    public function testSessionFileIoDoesNotReferenceHostDelegation(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmSession.php');
        $this->assertStringContainsString('VmFsUnlink::unlink', $source);
        $this->assertStringContainsString('VmFs::mkdir', $source);
        $this->assertStringContainsString('VmFs::filePutContents', $source);
        $this->assertStringContainsString('VmDir::scandir', $source);
        $this->assertStringContainsString('VmFs::fileMtime', $source);
        $this->assertStringContainsString('VmStatPath::isFile', $source);
        $this->assertStringContainsString('VmStatPath::isDir', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\unlink\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\mkdir\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\opendir\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\filemtime\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/(?<!VmFs::)file_put_contents\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/(?<!VmStatPath::)is_file\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/(?<!VmStatPath::)is_dir\\s*\\(/', $source);
    }

    public function testGcExpiredFilesDoesNotReferenceHostIniGet(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmSession.php');
        $this->assertStringContainsString('VmIni::getSessionGcMaxLifetime()', $source);
        $this->assertStringNotContainsString("function_exists('ini_get')", $source);
    }

    public function testSessionGcMaxlifetimeIniAffectsPurgeThreshold(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $dir = sys_get_temp_dir().'/phpc_gc_ini_'.getmypid();
        @mkdir($dir, 0700, true);
        putenv('PHP_COMPILER_SESSION_DIR='.$dir);

        VmIni::set($ctx, 'session.gc_maxlifetime', '60');

        $stale = $dir.'/'.SessionFileStorage::PATH_PREFIX.'deadbeef';
        file_put_contents($stale, 'x');
        touch($stale, time() - 120);

        $fresh = $dir.'/'.SessionFileStorage::PATH_PREFIX.'cafebabe';
        file_put_contents($fresh, 'y');
        touch($fresh, time() - 10);

        $code = <<<'PHP'
<?php
session_start();
echo session_gc(), "\n";
session_write_close();
PHP;
        $block = $runtime->parseAndCompile($code, 'session_gc_ini.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("1\n", ob_get_clean());
        self::assertFileDoesNotExist($stale);
        self::assertFileExists($fresh);
    }
}
