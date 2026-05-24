<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #1366 */
final class WeakReferenceWeakMapTest extends TestCase
{
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
}
