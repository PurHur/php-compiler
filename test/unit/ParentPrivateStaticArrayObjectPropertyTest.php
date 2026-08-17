<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Objects stored in persistent arrays must keep properties after the writer frame returns (#31937).
 *
 * Regression: FETCH_DIM_W left an INDIRECT into the HashTable bucket; releaseFrameObjectRefs
 * called releaseDirectObject through that alias, dropping ObjectEntry refcount so destroyForGc
 * wiped properties while the static table still held the pointer.
 */
final class ParentPrivateStaticArrayObjectPropertyTest extends TestCase
{
    public function testParentPrivateStaticArrayKeepsObjectProperties(): void
    {
        $code = <<<'PHP'
<?php
abstract class Registry {
    private static array $instances = [];
    public static function getInstance(): static {
        $class = static::class;
        if (!isset(self::$instances[$class])) {
            $obj = new static();
            $obj->name = 'singleton';
            self::$instances[$class] = $obj;
        }
        return self::$instances[$class];
    }
}
class Child extends Registry {
    public $name;
}
$inst = Child::getInstance();
echo $inst->name, "\n";
PHP;
        $this->assertSame("singleton\n", $this->runVm($code));
    }

    public function testFunctionStaticArrayKeepsObjectProperties(): void
    {
        $code = <<<'PHP'
<?php
function registry() {
    static $instances = [];
    if (!isset($instances['x'])) {
        $obj = new stdClass;
        $obj->name = 'singleton';
        $instances['x'] = $obj;
    }
    return $instances['x'];
}
echo registry()->name, "\n";
echo registry()->name, "\n";
PHP;
        $this->assertSame("singleton\nsingleton\n", $this->runVm($code));
    }

    public function testAppendToStaticArrayKeepsObjectProperties(): void
    {
        $code = <<<'PHP'
<?php
class Holder {
    private static array $items = [];
    public static function add($name) {
        $obj = new stdClass;
        $obj->name = $name;
        self::$items[] = $obj;
        return self::$items[count(self::$items) - 1];
    }
    public static function first() {
        return self::$items[0];
    }
}
echo Holder::add('alpha')->name, "\n";
echo Holder::first()->name, "\n";
PHP;
        $this->assertSame("alpha\nalpha\n", $this->runVm($code));
    }

    private function runVm(string $code): string
    {
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'parent_private_static_array_object_property.php');
        ob_start();
        $rt->run($block);

        return (string) ob_get_clean();
    }
}
