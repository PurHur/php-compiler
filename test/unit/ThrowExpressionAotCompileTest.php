<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only: throw expressions with sequential try/catch (#4041).
 *
 * AOT execute (catch dispatch) is tracked separately — EH in standalone binaries
 * still falls through merge without running catch (see issue #4041 PR notes).
 *
 * @group llvm
 */
final class ThrowExpressionAotCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — throw expression AOT compile test needs LLVM');
        }
    }

    public function testThrowExpressionPhptCompilesForAot(): void
    {
        $phpt = $this->repoRoot.'/test/compliance/cases/language/throw_expression.phpt';
        $raw = file_get_contents($phpt);
        $this->assertIsString($raw);
        if (!preg_match('/--FILE--\s*\n(.*?)\n--EXPECT--/s', $raw, $m)) {
            $this->fail('PHPT missing FILE section: '.$phpt);
        }
        $src = tempnam(sys_get_temp_dir(), 'phpc_throw_expr_');
        $this->assertNotFalse($src);
        file_put_contents($src, $m[1]);
        $out = $src.'_aot';
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            [PHP_BINARY, $this->repoRoot.'/bin/compile.php', '-o', $out, $src],
            $descriptorSpec,
            $pipes,
            $this->repoRoot,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($src);
        if (is_file($out)) {
            @unlink($out);
        }
        $this->assertSame(0, $exit, trim($stderr !== false ? $stderr : ''));
    }
}
