<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * ob_end_flush()/ob_end_clean() no-buffer E_NOTICE on stderr with display_errors=0 (#10677).
 */
final class ObNoBufferNoticeTest extends TestCase
{
    private const CODE = <<<'PHP'
error_reporting(E_ALL);
ini_set('display_errors', '0');
ob_end_flush();
ob_end_clean();
PHP;

    public function testVmStderrNoticeWithDisplayErrorsOff(): void
    {
        $stderr = $this->runVmCaptureStderr(self::CODE);
        $this->assertStringContainsString(
            'ob_end_flush(): Failed to delete and flush buffer. No buffer to delete or flush',
            $stderr
        );
        $this->assertStringContainsString(
            'ob_end_clean(): Failed to delete buffer. No buffer to delete',
            $stderr
        );
        $this->assertStringContainsString('PHP Notice:', $stderr);
    }

    private function runVmCaptureStderr(string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_ob_notice_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".$code);
        $proc = proc_open(
            ['php', $repo.'/bin/vm.php', $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($tmp);
        $this->assertSame(0, $exit, trim((string) $stderr));

        return (string) $stderr;
    }
}
