<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT lint gate for Native::compileArg coercions (issue #816 / self-host native link).
 *
 * @group llvm
 * @group aot
 * @group aot-lint
 */
final class NativeCompileArgCoercionTest extends TestCase
{
    public function testUserFunctionNativeIntParamLints(): void
    {
        $source = <<<'PHP'
<?php
function add(int $a, int $b): int {
    return $a + $b;
}
echo (string) add(1, 2);
PHP;

        $this->assertCompileLintExitZero($source);
    }

    public function testTypedCtorNativeIntArgsLint(): void
    {
        $source = <<<'PHP'
<?php
class MiniOp {
    public int $type;
    public int $arg1;

    public function __construct(int $type, int $arg1) {
        $this->type = $type;
        $this->arg1 = $arg1;
    }
}
$op = new MiniOp(1, 2);
echo (string) ($op->type + $op->arg1);
PHP;

        $this->assertCompileLintExitZero($source);
    }

    private function assertCompileLintExitZero(string $source): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }

        $repoRoot = dirname(__DIR__, 2);
        $compileBin = realpath($repoRoot.'/bin/compile.php');
        $this->assertNotFalse($compileBin);

        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repoRoot);

        $php = [PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL'];
        $compileArgv = array_merge(
            LlvmToolchain::envPrefix($repoRoot),
            $php,
            [$compileBin, '-l']
        );
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $compile = proc_open($compileArgv, $descriptorSpec, $pipes, $repoRoot, $env);
        fwrite($pipes[0], $source);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($compile);

        $this->assertSame(0, $exitCode, trim($stderr !== false ? $stderr : ''));
    }
}
