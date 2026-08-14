<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT compile-only: WeakMap ArrayAccess excess argc lowering (#30909).
 *
 * Native execute of catchable ArgumentCountError on this surface still aborts
 * (same class as WeakReference::create AOT in #30867). VM/JIT are the
 * php-src-strict execute gates.
 *
 * php-src: Zend/zend_weakrefs.c / zend_weakrefs.stub.php
 *
 * @group llvm
 * @group aot
 */
final class Issue30909WeakMapExcessArgcAotTest extends TestCase
{
    public function testAotWeakMapExcessArgcCompiles(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        // Explicit method calls only — isset($wm[$k]) AOT still compiles every
        // ArrayAccess candidate (ArrayObject::offsetExists argc 0, #27244 sibling).
        $src = sys_get_temp_dir().'/phpc_30909_aot_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
function show($l, $fn) {
  try { $r = $fn(); echo $l, ": ", var_export($r, true), "\n"; }
  catch (Throwable $e) { echo $l, ": ", get_class($e), ": ", $e->getMessage(), "\n"; }
}
$wm = new WeakMap(); $o = new stdClass(); $wm[$o] = 1;
show("offsetExists", fn() => $wm->offsetExists($o, 1));
show("offsetGet", fn() => $wm->offsetGet($o, 1));
show("offsetUnset", function() use ($wm, $o) { $wm->offsetUnset($o, 1); return "ok"; });
show("offsetExists_ok", fn() => $wm->offsetExists($o));
show("offsetGet_ok", fn() => $wm->offsetGet($o));
PHP);
        $bin = sys_get_temp_dir().'/phpc_30909_aot_'.getmypid().'.bin';
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
