<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT filter_var_array must return filtered array, not empty VmIni (#34574).
 *
 * @group llvm
 * @group aot
 */
final class FilterVarArray34574AotTest extends TestCase
{
    public function testFilterIdFormMatchesZend(): void
    {
        $this->assertAotExport(
            "<?php var_export(filter_var_array(['a'=>'1','b'=>'x'], FILTER_VALIDATE_INT));",
            "array (\n  'a' => 1,\n  'b' => false,\n)"
        );
    }

    public function testDefinitionArrayFormMatchesZend(): void
    {
        $this->assertAotExport(
            "<?php var_export(filter_var_array(['a'=>'1'], ['a'=>FILTER_VALIDATE_INT]));",
            "array (\n  'a' => 1,\n)"
        );
    }

    public function testRuntimeArrayFilterIdMatchesZend(): void
    {
        $this->assertAotExport(
            "<?php \$d=['a'=>'1','b'=>'x']; var_export(filter_var_array(\$d, FILTER_VALIDATE_INT));",
            "array (\n  'a' => 1,\n  'b' => false,\n)"
        );
    }

    private function assertAotExport(string $code, string $expected): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $dir = sys_get_temp_dir().'/phpc-fva-34574-'.getmypid().'-'.mt_rand();
        mkdir($dir);
        $src = $dir.'/t.php';
        $bin = $dir.'/t.bin';
        file_put_contents($src, $code);
        $env = getenv();
        if (!\is_array($env)) {
            $env = [];
        }
        $env['PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR'] = $dir.'/helper-cache';
        mkdir($env['PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR']);
        $cmd = [
            PHP_BINARY,
            '-d', 'memory_limit=512M',
            dirname(__DIR__, 2).'/bin/compile.php',
            '-o', $bin,
            $src,
        ];
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $desc, $pipes, dirname(__DIR__, 2), $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);
        $this->assertSame(0, $rc, "compile failed: $stdout$stderr");
        $this->assertFileExists($bin);
        $out = [];
        $runRc = 0;
        exec(escapeshellarg($bin).' 2>&1', $out, $runRc);
        $this->assertSame(0, $runRc, 'run failed: '.implode("\n", $out));
        $this->assertSame($expected, implode("\n", $out));
    }
}
