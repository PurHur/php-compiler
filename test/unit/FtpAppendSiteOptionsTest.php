<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\Test\Support\BuiltinStubEnumTestSkip;
use PHPUnit\Framework\TestCase;

/**
 * ftp_append/SITE/options surface (#20060).
 *
 * @covers issue #20060
 */
final class FtpAppendSiteOptionsTest extends TestCase
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

    public function testAppendSiteOptionsExistAndNullTypeError(): void
    {
        $this->skipUnlessBuiltinStubEnumsEnabled();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
foreach (['ftp_append','ftp_alloc','ftp_chmod','ftp_raw','ftp_site','ftp_set_option','ftp_get_option'] as $f) {
    echo $f, '=', (int) function_exists($f), "\n";
}
try {
    ftp_set_option(null, FTP_TIMEOUT_SEC, 90);
    echo "ok\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'ftp_append_exists.php'));
        $out = ob_get_clean();
        $this->assertSame(
            "ftp_append=1\nftp_alloc=1\nftp_chmod=1\nftp_raw=1\nftp_site=1\nftp_set_option=1\nftp_get_option=1\n"
            ."ftp_set_option(): Argument #1 (\$ftp) must be of type FTP\\Connection, null given\n",
            $out
        );
    }

    public function testSetGetOptionTimeoutRoundTripAgainstMock(): void
    {
        $this->skipUnlessBuiltinStubEnumsEnabled();
        if (!\function_exists('ftp_connect') || !\function_exists('proc_open')) {
            $this->markTestSkipped('host ftp_connect/proc_open required for mock');
        }

        $serverScript = dirname(__DIR__).'/fixtures/ftp/mock_greeting_server.php';
        $port = 23000 + (getmypid() % 1000);
        $readyFile = sys_get_temp_dir().'/ftp20060-ready-'.getmypid().'.txt';
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
\$set = ftp_set_option(\$conn, FTP_TIMEOUT_SEC, 90);
\$get = ftp_get_option(\$conn, FTP_TIMEOUT_SEC);
echo 'set=', (int) \$set, ' get=', \$get, "\\n";
\$raw = ftp_raw(\$conn, 'NOOP');
echo 'raw0=', is_array(\$raw) ? \$raw[0] : 'null', "\\n";
ftp_close(\$conn);
PHP;
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'ftp_option_rt.php'));
            $out = ob_get_clean();
            $this->assertSame("set=1 get=90\nraw0=502 not implemented\n", $out);
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
