<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for pfsockopen() persistent TCP sockets (issue #3384). */
final class PfsockopenBuiltinTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/../compliance/cases/stdlib/pfsockopen.phpt';
        yield 'pfsockopen.phpt' => self::parsePHPT($path, 'pfsockopen.phpt');
    }

    /**
     * @group network
     */
    public function testPersistentReuseOnEphemeralServer(): void
    {
        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (false === $server) {
            $this->markTestSkipped('stream_socket_server unavailable: '.$errstr);

            return;
        }
        $bound = \stream_socket_get_name($server, false);
        if (false === $bound || !\is_string($bound)) {
            \fclose($server);
            $this->markTestSkipped('stream_socket_get_name failed');

            return;
        }
        $colon = \strrpos($bound, ':');
        if (false === $colon) {
            \fclose($server);
            $this->markTestSkipped('unexpected bind address: '.$bound);

            return;
        }
        $host = \substr($bound, 0, $colon);
        $port = (int) \substr($bound, $colon + 1);

        $code = <<<'PHP'
<?php
$errno = 0;
$errstr = '';
$fp1 = @pfsockopen('%s', %d, $errno, $errstr, 2);
$fp2 = @pfsockopen('%s', %d, $errno, $errstr, 2);
echo is_resource($fp1) ? "r1\n" : "no1\n";
echo is_resource($fp2) ? "r2\n" : "no2\n";
if (is_resource($fp1)) {
    fclose($fp1);
}
if (is_resource($fp2)) {
    fclose($fp2);
}
PHP;
        $script = \sprintf($code, $host, $port, $host, $port);
        try {
            $output = $this->runVmScript($script);
            $this->assertStringContainsString("r1\n", $output);
            $this->assertStringContainsString("r2\n", $output);
        } finally {
            \fclose($server);
        }
    }

    private function runVmScript(string $code): string
    {
        $runtime = new Runtime(Runtime::MODE_NORMAL);
        $block = $runtime->parseAndCompile($code, 'pfsockopen-test.php');
        $this->assertNotNull($block);

        \ob_start();
        $runtime->run($block);
        $out = \ob_get_clean();

        return \is_string($out) ? $out : '';
    }
}
