<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\LlvmToolchain;
use PHPCompiler\Runtime;
use PHPCompiler\VM\ScriptExit;
use PHPUnit\Framework\TestCase;

/** @covers issue #4696 */
final class ExitStatusCoercionTest extends TestCase
{
    /**
     * @dataProvider provideCoercedStatus
     */
    public function testExitCoercesZendLegalScalars(string $code, string $expectedOutput, int $expectedStatus): void
    {
        $runtime = new Runtime();
        ob_start();
        try {
            $runtime->run($runtime->parseAndCompile($code, 'exit_coerce.php'));
            $this->fail('Expected ScriptExit');
        } catch (ScriptExit $e) {
            $this->assertSame($expectedStatus, $e->status);
        }
        $this->assertSame($expectedOutput, ob_get_clean());
    }

    public static function provideCoercedStatus(): array
    {
        return [
            'float' => ['<?php exit(1.5);', '1.5', 0],
            'bool true' => ['<?php exit(true);', '1', 0],
            'null' => ['<?php exit(null);', '', 0],
            'bool false' => ['<?php exit(false);', '', 0],
            'array' => ['<?php exit([]);', 'Array', 0],
        ];
    }

    public function testExitArrayStatusEmitsWarningOnStderr(): void
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_exit_array_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, '<?php exit([]);');
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

        $this->assertSame(0, $exit);
        $this->assertSame('Array', $stdout !== false ? $stdout : '');
        $this->assertMatchesRegularExpression(
            '/^PHP Warning:\s+Array to string conversion in .+( on line \d+)?\s*$/m',
            $stderr !== false ? $stderr : ''
        );
    }
}
