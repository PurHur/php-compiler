<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** CLI warning stream + Zend prefix parity (issue #4488). */
final class WarningStderrTest extends TestCase
{

    public function testCompactUndefinedVariableWarningOnStderrBeforeStdout(): void
    {
        $code = <<<'PHP'
<?php
$a = 1;
var_dump(compact('a', 'b'));
PHP;
        [$stdout, $stderr, $exit] = $this->runVmCapturingStreams($code);
        $this->assertSame(0, $exit, $stderr ?: 'VM run failed');
        $this->assertMatchesRegularExpression(
            '/^PHP Warning:\s+compact\(\): Undefined variable \$b in .+ on line \d+\s*$/m',
            $stderr
        );
        $this->assertStringNotContainsString('PHP Warning:', $stdout);
        $this->assertStringContainsString('array(1)', $stdout);
        $this->assertStringContainsString('["a"]', $stdout);
        $warningPos = strpos($stderr, 'PHP Warning:');
        $this->assertNotFalse($warningPos);
        $this->assertSame(0, $warningPos, 'warning must lead stderr');
    }

    public function testUserErrorHandlerStillReceivesRawMessage(): void
    {
        $code = <<<'PHP'
<?php
function h($errno, $errstr, $errfile, $errline) {
    echo "handled:$errno:$errstr\n";
    return true;
}
set_error_handler('h');
trigger_error('test notice', 1024);
restore_error_handler();
PHP;
        [$stdout, $stderr] = $this->runVmCapturingStreams($code);
        $this->assertSame("handled:1024:test notice\n", $stdout);
        $this->assertStringNotContainsString('PHP Notice:', $stderr);
    }

    /**
     * @return array{0: string, 1: string, 2: int}
     */
    private function runVmCapturingStreams(string $code): array
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_warn_stderr_');
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
