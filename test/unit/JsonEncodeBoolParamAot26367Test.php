<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: json_encode() on bool params must emit true/false not 0/1 (#26367 follow-up).
 *
 * @group llvm
 * @group aot
 */
final class JsonEncodeBoolParamAot26367Test extends TestCase
{
    public function testVmJsonEncodeBoolParamMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = <<<'PHP'
<?php
function s($b): void { echo json_encode($b), "\n"; }
s(false);
PHP;
        $path = sys_get_temp_dir().'/phpc_json_bool_param_'.getmypid().'.php';
        file_put_contents($path, $src);
        try {
            $zend = [];
            $vm = [];
            exec('php '.escapeshellarg($path).' 2>/dev/null', $zend, $zendRc);
            exec(
                'php '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($path).' 2>/dev/null',
                $vm,
                $vmRc
            );
            self::assertSame(0, $zendRc);
            self::assertSame(0, $vmRc);
            self::assertSame('false', trim($zend[0] ?? ''));
            self::assertSame(implode("\n", $zend), implode("\n", $vm));
        } finally {
            @unlink($path);
        }
    }

    public function testAotJsonEncodeBoolParamMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = <<<'PHP'
<?php
function s($b): void { echo json_encode($b), "\n"; }
s(false);
PHP;
        $path = sys_get_temp_dir().'/phpc_json_bool_param_aot_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_json_bool_param_aot_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            self::assertSame(0, $compileRc, implode("\n", $compileOut));
            $zend = [];
            exec('php '.escapeshellarg($path).' 2>/dev/null', $zend, $zendRc);
            self::assertSame(0, $zendRc);
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            self::assertSame(0, $runRc, implode("\n", $runOut));
            self::assertSame(implode("\n", $zend), implode("\n", $runOut));
        } finally {
            @unlink($path);
            @unlink($bin);
        }
    }
}
