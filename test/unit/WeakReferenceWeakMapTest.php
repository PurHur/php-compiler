<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #1366 */
final class WeakReferenceWeakMapTest extends TestCase
{
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
}
