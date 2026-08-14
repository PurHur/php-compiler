<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT compile-only: WeakMap::count() excess argc lowering (#31129).
 *
 * Native execute of catchable ArgumentCountError on this surface still aborts
 * (same class as WeakMap ArrayAccess AOT in #30909). VM/JIT are the
 * php-src-strict execute gates.
 *
 * php-src: Zend/zend_weakrefs.c / zend_weakrefs.stub.php
 *
 * @group llvm
 * @group aot
 */
final class Issue31129WeakMapCountExcessArgcAotTest extends TestCase
{
    public function testAotWeakMapCountExcessArgcCompiles(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_31129_aot_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
function show($l, $fn) {
  try { $r = $fn(); echo $l, ": ", var_export($r, true), "\n"; }
  catch (Throwable $e) { echo $l, ": ", get_class($e), ": ", $e->getMessage(), "\n"; }
}
$wm = new WeakMap(); $o = new stdClass(); $wm[$o] = 1;
show("count_excess", fn() => $wm->count(1));
show("count_ok", fn() => $wm->count());
show("count_fn", fn() => count($wm));
PHP);
        $bin = sys_get_temp_dir().'/phpc_31129_aot_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        try {
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $this->assertFileExists($bin);
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
