<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\Test\Support\BuiltinStubEnumTestSkip;
use PHPUnit\Framework\TestCase;

/**
 * ftp_fget stream TypeError + mock greeting smoke (#6762).
 *
 * @covers issue #6762
 */
final class FtpStreamApiTest extends TestCase
{
    use BuiltinStubEnumTestSkip;

    /** @var false|string */
    private $savedProfile;

    protected function setUp(): void
    {
        $this->savedProfile = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
    }

    protected function tearDown(): void
    {
        if (false === $this->savedProfile) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$this->savedProfile);
        }
    }

    public function testFtpFgetExistsAndNullTypeError(): void
    {
        $this->skipUnlessBuiltinStubEnumsEnabled();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
echo (int) function_exists('ftp_fget'), (int) function_exists('ftp_mlsd'), "\n";
$fp = fopen('php://memory', 'r+b');
try {
    ftp_fget(null, $fp, 'x', FTP_BINARY);
    echo "ok\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'ftp_fget_exists.php'));
        $out = ob_get_clean();
        $this->assertSame(
            "11\nftp_fget(): Argument #1 (\$ftp) must be of type FTP\\Connection, null given\n",
            $out
        );
    }

    public function testFtpFgetIntStreamTypeErrorAgainstMock(): void
    {
        $this->skipUnlessBuiltinStubEnumsEnabled();
        if (!\function_exists('ftp_connect') || !\function_exists('proc_open')) {
            $this->markTestSkipped('host ftp_connect/proc_open required for mock');
        }

        $serverScript = dirname(__DIR__).'/fixtures/ftp/mock_greeting_server.php';
        $port = 22000 + (getmypid() % 1000);
        $readyFile = sys_get_temp_dir().'/ftp6762-ready-'.getmypid().'.txt';
        @unlink($readyFile);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $phpBin = \defined('PHP_BINARY') && '' !== (string) PHP_BINARY
            ? (string) PHP_BINARY
            : 'php';
        $cmd = escapeshellarg($phpBin).' '.escapeshellarg($serverScript).' '.$port.' '.escapeshellarg($readyFile);
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (false === $proc) {
            $this->markTestSkipped('proc_open failed');
        }
        $ready = false;
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            if (is_file($readyFile) && 'ready' === trim((string) @file_get_contents($readyFile))) {
                $ready = true;
                break;
            }
            usleep(20000);
        }
        if (!$ready) {
            proc_terminate($proc);
            proc_close($proc);
            @unlink($readyFile);
            $this->markTestSkipped('mock FTP server not ready');
        }

        try {
            $runtime = new Runtime();
            $code = <<<PHP
<?php
\$conn = ftp_connect('127.0.0.1', {$port}, 2);
if (false === \$conn) {
    echo "connect_fail\\n";
    exit(0);
}
try {
    ftp_fget(\$conn, 42, 'remote.bin', FTP_BINARY);
    echo "ok\\n";
} catch (TypeError \$e) {
    echo \$e->getMessage(), "\\n";
}
ftp_close(\$conn);
PHP;
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'ftp_fget_stream_te.php'));
            $out = ob_get_clean();
            $this->assertSame(
                "ftp_fget(): Argument #2 (\$stream) must be of type resource, int given\n",
                $out
            );
        } finally {
            foreach ($pipes as $p) {
                if (\is_resource($p)) {
                    fclose($p);
                }
            }
            proc_terminate($proc);
            proc_close($proc);
            @unlink($readyFile);
        }
    }
}
