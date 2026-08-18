<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Undefined variable E_WARNING includes Zend " on line N" suffix (#13390, #13469). */
final class UndefinedVariableWarningLineTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testTopLevelUndefinedVariableWarningIncludesLineSuffix(): void
    {
        $script = $this->repoRoot.'/test/repro/maintainer_gap_undefined_var_warning_line.php';
        [, $stderr] = $this->runVmScript($script, 0);
        $this->assertMatchesRegularExpression(
            '/Undefined variable \$missing in .+maintainer_gap_undefined_var_warning_line\.php on line 5\s*$/m',
            $stderr
        );
    }

    public function testNestedUndefinedVariableWarningUsesReadSiteNotCallSite(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_undef_line_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, <<<'PHP'
<?php
function inner(): void
{
    echo $missing;
}
inner();
PHP
        );
        [, $stderr] = $this->runVmScript($tmp, 0);
        @unlink($tmp);
        $this->assertMatchesRegularExpression('/ on line 4$/m', $stderr);
        $this->assertStringNotContainsString('on line 6', $stderr);
    }

    public function testFunctionUndefinedVariableWarningCitesInnerEchoLine(): void
    {
        $script = $this->repoRoot.'/test/repro/maintainer_gap_undef_var_function_line.php';
        [, $stderr] = $this->runVmScript($script, 0);
        $this->assertMatchesRegularExpression(
            '/Undefined variable \$missing in .+maintainer_gap_undef_var_function_line\.php on line 6\s*$/m',
            $stderr
        );
        $this->assertStringNotContainsString('on line 9', $stderr);
    }

    public function testMethodUndefinedVariableWarningCitesInnerEchoLine(): void
    {
        $script = $this->repoRoot.'/test/repro/maintainer_gap_undef_var_method_line.php';
        [, $stderr] = $this->runVmScript($script, 0);
        $this->assertMatchesRegularExpression(
            '/Undefined variable \$missing in .+maintainer_gap_undef_var_method_line\.php on line 8\s*$/m',
            $stderr
        );
        $this->assertStringNotContainsString('on line 12', $stderr);
    }

    public function testClosureUndefinedVariableErrorGetLastCitesInnerLine(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_undef_closure_line_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, <<<'PHP'
<?php
error_reporting(E_ALL);
$fn = function () {
    echo $missing, "\n";
};
$fn();
$e = error_get_last();
echo 'last:', $e['line'] ?? 0, "\n";
PHP
        );
        [$stdout, $stderr] = $this->runVmScript($tmp, 0);
        @unlink($tmp);
        $this->assertMatchesRegularExpression('/ on line 4\s*$/m', $stderr);
        $this->assertStringNotContainsString('on line 6', $stderr);
        $this->assertSame("\nlast:4\n", $stdout);
    }

    public function testBinaryExprUndefinedVariableWarningIncludesLineSuffix(): void
    {
        $script = $this->repoRoot.'/test/repro/maintainer-probe/batch3_undefined_var_arith.php';
        [, $stderr] = $this->runVmScript($script, 0);
        $this->assertMatchesRegularExpression(
            '/Undefined variable \$missing in .+batch3_undefined_var_arith\.php on line 5\s*$/m',
            $stderr
        );
    }

    public function testEncapsedUndefinedVariableWarningCitesExpressionLine(): void
    {
        $script = $this->repoRoot.'/test/repro/maintainer_gap_undef_var_encapsed_line.php';
        [, $stderr] = $this->runVmScript($script, 0);
        $this->assertMatchesRegularExpression(
            '/Undefined variable \$missing in .+maintainer_gap_undef_var_encapsed_line\.php on line 3/m',
            $stderr
        );
        $this->assertMatchesRegularExpression(
            '/Undefined variable \$missing in .+maintainer_gap_undef_var_encapsed_line\.php on line 4/m',
            $stderr
        );
        $this->assertMatchesRegularExpression(
            '/Undefined variable \$missing in .+maintainer_gap_undef_var_encapsed_line\.php on line 5/m',
            $stderr
        );
        $this->assertMatchesRegularExpression(
            '/Undefined variable \$missing in .+maintainer_gap_undef_var_encapsed_line\.php on line 6/m',
            $stderr
        );
        $this->assertDoesNotMatchRegularExpression(
            '/Undefined variable \$missing in .+maintainer_gap_undef_var_encapsed_line\.php on line 2/m',
            $stderr
        );
    }

    public function testHeredocUndefinedVariableWarningCitesBodyLine(): void
    {
        $script = $this->repoRoot.'/test/repro/maintainer_gap_undef_var_heredoc_line.php';
        [, $stderr] = $this->runVmScript($script, 0);
        $this->assertMatchesRegularExpression(
            '/Undefined variable \$missing in .+maintainer_gap_undef_var_heredoc_line\.php on line 4\s*$/m',
            $stderr
        );
        $this->assertDoesNotMatchRegularExpression(
            '/Undefined variable \$missing in .+maintainer_gap_undef_var_heredoc_line\.php on line 2/m',
            $stderr
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function runVmScript(string $scriptPath, int $expectedExit): array
    {
        $proc = proc_open(
            [PHP_BINARY, $this->repoRoot.'/bin/vm.php', $scriptPath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->repoRoot
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame($expectedExit, proc_close($proc), trim($stderr."\n".$stdout));

        return [(string) $stdout, (string) $stderr];
    }
}
