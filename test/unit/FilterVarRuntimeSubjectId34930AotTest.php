<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: filter_var($runtimeSubject, $runtimeFilterId) must verify (#34930).
 *
 * Mega-CFG sanitize arm tagged __value__readLong (i64) as TYPE_NATIVE_BOOL;
 * jitScalarToString branchIf required i1.
 *
 * @see php-src ext/filter/filter.c php_filter_call
 *
 * @group llvm
 * @group aot
 */
final class FilterVarRuntimeSubjectId34930AotTest extends TestCase
{
    private const EXPECTED = "'a@b.co'\n42\n";

    public function testVmFilterVarRuntimeSubjectAndId(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_34930_filter_var_runtime_subject_id.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34930_filter_var_runtime_subject_id.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotFilterVarRuntimeSubjectAndId(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34930_filter_var_runtime_subject_id.php';
        $bin = sys_get_temp_dir().'/phpc_issue_34930_fv_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
