<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #1366 */
final class WeakReferenceWeakMapTest extends TestCase
{
    /** @covers issue #25962 — zend_weakrefs.c clone_obj unset */
    public function testWeakReferenceCloneThrowsUncloneableError(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$o = new stdClass;
$w = WeakReference::create($o);
echo 'cloneable=', (new ReflectionClass(WeakReference::class))->isCloneable() ? '1' : '0', "\n";
echo 'get=', var_export($w->get() === $o, true), "\n";
try {
    $c = clone $w;
    echo 'cloned=', get_class($c), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'weakref_clone_25962.php'));
        $this->assertSame(
            "cloneable=0\nget=true\nError:Trying to clone an uncloneable object of class WeakReference\n",
            ob_get_clean()
        );
    }

    public function testWeakReferenceGetNullAfterAssignNull(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$o = new stdClass();
$r = WeakReference::create($o);
$o = null;
echo $r->get() === null ? '1' : '0';
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'weakref_assign_null.php'));
        $this->assertSame('1', ob_get_clean());
    }

    /** @covers issue #24592 */
    public function testFiberWeakReferenceReflectionAndNamedArgs(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
foreach ([[Fiber::class, '__construct'], [WeakReference::class, 'create']] as [$c, $m]) {
    $r = new ReflectionMethod($c, $m);
    $ns = [];
    foreach ($r->getParameters() as $p) {
        $ns[] = $p->getName();
    }
    echo "$c::$m arity=", $r->getNumberOfParameters(), ' [', implode(',', $ns), "]\n";
}
$f = new Fiber(callback: function () {});
echo 'fiber_named=', $f->isStarted() ? 'started' : 'ok', "\n";
$obj = new stdClass();
$wr = WeakReference::create(object: $obj);
echo 'wr_named=', null === $wr->get() ? 'null' : 'ok', "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'fiber_wr_named_24592.php'));
        $this->assertSame(
            "Fiber::__construct arity=1 [callback]\n"
            . "WeakReference::create arity=1 [object]\n"
            . "fiber_named=ok\n"
            . "wr_named=ok\n",
            ob_get_clean()
        );
    }

    public function testWeakReferenceGetNullAfterUnset(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Box {}
$o = new Box();
$r = WeakReference::create($o);
unset($o);
echo $r->get() === null ? '1' : '0';
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'weakref_unset.php'));
        $this->assertSame('1', ob_get_clean());
    }

    public function testWeakReferenceGetNullAfterCompareEchoUnset(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Box {}
$o = new Box();
$r = WeakReference::create($o);
echo $r->get() !== null ? '1' : '0';
unset($o);
echo $r->get() === null ? '1' : '0';
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'weakref_compare.php'));
        $this->assertSame('11', ob_get_clean());
    }

    public function testWeakReferenceGetReturnsObjectWhileAlive(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Box {}
$o = new Box();
$r = WeakReference::create($o);
echo $r->get() === $o ? '1' : '0';
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'weakref_alive.php'));
        $this->assertSame('1', ob_get_clean());
    }

    public function testClassExistsWeakReferenceAndWeakMap(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
echo class_exists('WeakReference') && class_exists('WeakMap') ? '1' : '0';
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'weak_classes.php'));
        $this->assertSame('1', ob_get_clean());
    }

    /** @covers issue #24432 — Zend/zend_weakrefs.c Error, not LogicException */
    public function testWeakReferenceDirectConstructThrowsError(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
try {
    new WeakReference(new stdClass());
    echo 'FAIL';
} catch (Throwable $e) {
    echo get_class($e), '|', $e->getMessage();
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'weakref_direct_construct.php'));
        $this->assertSame(
            'Error|Direct instantiation of WeakReference is not allowed, use WeakReference::create instead',
            ob_get_clean()
        );
    }

    public function testWeakMapOffsetSetAndGet(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Box {}
$m = new WeakMap();
$k = new Box();
$m->offsetSet($k, 42);
echo $m->offsetGet($k);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'weakmap_basic.php'));
        $this->assertSame('42', ob_get_clean());
    }

    /** Missing WeakMap key throws Error (zend_weakmap_offset_get, #24771). */
    public function testWeakMapOffsetGetMissingKeyThrowsError(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$wm = new WeakMap();
$o = new stdClass();
try {
    $wm[$o];
    echo 'no_throw';
} catch (Error $e) {
    echo preg_replace('/#\d+/', '#N', $e->getMessage());
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'weakmap_missing_key.php'));
        $this->assertSame('Object stdClass#N not contained in WeakMap', ob_get_clean());
    }

    public function testWeakMapCountAndOffsetExists(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Box {}
$m = new WeakMap();
$k = new Box();
$m->offsetSet($k, 1);
echo $m->count();
echo $m->offsetExists($k) ? '1' : '0';
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'weakmap_count.php'));
        $this->assertSame('11', ob_get_clean());
    }

    /** offsetSet after gc_collect_cycles clears weak keys (#24270). */
    public function testWeakMapOffsetSetAfterGcCollectCycles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$o = new stdClass();
$w = new WeakMap();
$w[$o] = 42;
unset($o);
gc_collect_cycles();
$o2 = new stdClass();
$w[$o2] = 99;
echo 'val='.$w[$o2].' count='.$w->count()."\n";
foreach ($w as $k => $v) {
    echo 'iter='.$v."\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'weakmap_insert_after_gc.php'));
        $out = ob_get_clean();
        $this->assertStringContainsString('val=99 count=1', $out);
        $this->assertStringContainsString('iter=99', $out);
    }

    public function testWeakReferenceParsesAndCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Box {}
$o = new Box();
$r = WeakReference::create($o);
PHP;
        $runtime->parseAndCompile($code, 'weakref_parse.php');
        $this->addToAssertionCount(1);
    }

    public function testWeakReferenceCreateEnumCase(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E { case A; }
$w = WeakReference::create(E::A);
echo $w->get() === E::A ? '1' : '0';
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'weakref_enum.php'));
        $this->assertSame('1', ob_get_clean());
    }

    public function testWeakReferenceCreateRejectsInt(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
try {
    WeakReference::create(1);
    echo 'ok';
} catch (TypeError) {
    echo 'err';
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'weakref_int.php'));
        $this->assertSame('err', ob_get_clean());
    }

    public function testWeakMapEnumCaseKey(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum F: string { case B = 'x'; }
$map = new WeakMap();
$map[F::B] = 'v';
echo $map[F::B];
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'weakmap_enum.php'));
        $this->assertSame('v', ob_get_clean());
    }

    public function testWeakMapIntBackedEnumCaseKey(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E: int { case A = 1; }
$m = new WeakMap();
$m[E::A] = 42;
echo $m[E::A];
echo isset($m[E::A]) ? '1' : '0';
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'weakmap_int_enum.php'));
        $this->assertSame('421', ob_get_clean());
    }

    public function testWeakMapUnitEnumCaseKey(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum U { case C; }
$m = new WeakMap();
$m[U::C] = 'unit';
echo $m[U::C];
echo isset($m[U::C]) ? '1' : '0';
unset($m[U::C]);
echo isset($m[U::C]) ? '1' : '0';
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'weakmap_unit_enum.php'));
        $this->assertSame('unit10', ob_get_clean());
    }

    public function testWeakMapNonObjectKeyWriteCatchable(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$m = new WeakMap();
try {
    $m[1] = 'bad';
    echo 'ok';
} catch (TypeError) {
    echo 'err';
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'weakmap_int_key.php'));
        $this->assertSame('err', ob_get_clean());
    }

    public function testWeakReferenceGetNullAfterInlineNew(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$ref = WeakReference::create(new stdClass());
echo $ref->get() === null ? '1' : '0';
echo "\n";
echo get_debug_type($ref->get());
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'weakref_inline_new.php'));
        $this->assertSame("1\nnull", ob_get_clean());
    }

    public function testWeakReferenceGetNullAfterGcCollect(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Box {}
$o = new Box();
$r = WeakReference::create($o);
unset($o);
gc_collect_cycles();
echo $r->get() === null ? '1' : '0';
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'weakref_gc.php'));
        $this->assertSame('1', ob_get_clean());
    }

    public function testWeakMapEntryRemovedAfterGcCollect(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Box {}
$m = new WeakMap();
$k = new Box();
$m->offsetSet($k, 99);
unset($k);
gc_collect_cycles();
echo $m->count();
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'weakmap_gc.php'));
        $this->assertSame('0', ob_get_clean());
    }

    /** Zend clears weak-map keys when the last strong ref drops — no gc_collect_cycles() (#14103). */
    public function testWeakMapEntryRemovedImmediatelyOnKeyNull(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$k = new stdClass();
$wm = new WeakMap();
$wm[$k] = 42;
$k = null;
echo count($wm);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'weakmap_immediate_gc.php'));
        $this->assertSame('0', ob_get_clean());
    }

    /**
     * unset($o) on {main} must not clear WeakMap while foreach $k still holds the key (#24784).
     *
     * @see https://github.com/php/php-src/blob/master/Zend/zend_weakrefs.c
     */
    public function testWeakMapCountSurvivesUnsetWhileForeachKeyLive(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$wm = new WeakMap();
$o = new stdClass;
$wm[$o] = 1;
foreach ($wm as $k => $v) {
}
echo 'k_same=', ($k === $o) ? 'yes' : 'no', "\n";
unset($o);
echo 'count_with_k=', count($wm), "\n";
unset($k);
gc_collect_cycles();
echo 'count_after_k=', count($wm), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'weakmap_foreach_key_unset.php'));
        $this->assertSame(
            "k_same=yes\ncount_with_k=1\ncount_after_k=0\n",
            ob_get_clean()
        );
    }

    /** probe_weakreference: get() in if() must not leak a strong ref to the referent (#14103). */
    public function testWeakReferenceClearedAfterIfGetComparison(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$o = new stdClass();
$wr = WeakReference::create($o);
if ($wr->get() !== $o) {
    fwrite(STDERR, "live referent mismatch\n");
    exit(1);
}
$o = null;
if (null !== $wr->get()) {
    fwrite(STDERR, "collected referent must be null\n");
    exit(1);
}
echo "ok\n";
PHP;
        ob_start();
        try {
            $runtime->run($runtime->parseAndCompile($code, 'probe_weakreference.php'));
        } catch (\PHPCompiler\VM\ScriptExit $e) {
            $this->fail('probe_weakreference must not exit: '.$e->getMessage());
        }
        $this->assertSame("ok\n", ob_get_clean());
    }

    /** WeakMap offset read inside closure must see live key (#14132, Zend/zend_weakrefs.c). */
    public function testWeakMapOffsetGetInsideClosure(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$read = (function (): int {
    $wm = new WeakMap();
    $o = new stdClass();
    $wm[$o] = 9;
    return $wm[$o];
})();
echo $read === 9 ? 'ok' : 'fail';
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'weakmap_closure_offsetget.php'));
        $this->assertSame('ok', ob_get_clean());
    }

    /** WeakReference::get() inside closure must return referent (#14132). */
    public function testWeakReferenceGetInsideClosure(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$alive = (function (): bool {
    $o = new stdClass();
    $wr = WeakReference::create($o);
    return $wr->get() === $o;
})();
echo $alive ? 'ok' : 'fail';
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'weakreference_closure_get.php'));
        $this->assertSame('ok', ob_get_clean());
    }
}
