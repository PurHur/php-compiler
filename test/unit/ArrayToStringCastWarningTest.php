<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** (string) cast on array emits E_WARNING (zend_operators.c, issue #5266). */
final class ArrayToStringCastWarningTest extends TestCase
{
    public function testStringCastOnArrayEmitsWarningOnStderr(): void
    {
        $code = <<<'PHP'
<?php
echo (string) ([]), "\n";
PHP;
        [$stdout, $stderr, $exit] = $this->runVmCapturingStreams($code);
        $this->assertSame(0, $exit, $stderr ?: 'VM run failed');
        $this->assertSame("Array\n", $stdout);
        $this->assertMatchesRegularExpression(
            '/^PHP Warning:\s+Array to string conversion in .+ on line \d+\s*$/m',
            $stderr
        );
        $this->assertStringNotContainsString('PHP Warning:', $stdout);
    }

    /**
     * @return array{0: string, 1: string, 2: int}
     */
    private function runVmCapturingStreams(string $code): array
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_array_str_warn_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $code);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(
            [PHP_BINARY, $repo.'/bin/vm.php', $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($tmp);

        return [
            $stdout !== false ? $stdout : '',
            $stderr !== false ? $stderr : '',
            $exit,
        ];
    }
}
