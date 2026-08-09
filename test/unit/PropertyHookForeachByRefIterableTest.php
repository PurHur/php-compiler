<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\Test\Support\PropertyHookTestSkip;
use PHPUnit\Framework\TestCase;

/**
 * foreach ($obj->hookedArray as &$v) without &get → Indirect modification Error (#29215).
 *
 * php-src: Zend/zend_property_hooks.c / zend_execute.c FE_RESET_RW
 */
final class PropertyHookForeachByRefIterableTest extends TestCase
{
    use PropertyHookTestSkip;

    protected function setUp(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
    }

    public function testVmForeachByRefOnHookedArrayWithoutByRefGetErrors(): void
    {
        $code = <<<'PHP'
<?php
class H {
    public array $items {
        get => $this->items;
        set => $this->items = $value;
    }
}
$h = new H;
$h->items = [1, 2, 3];
try {
    foreach ($h->items as &$v) {
        $v *= 10;
    }
    unset($v);
    echo 'mutated';
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
    echo implode(',', $h->items);
}
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'foreach_byref_hooked.php');
        ob_start();
        $rt->run($block);
        self::assertSame(
            "Error:Indirect modification of H::\$items is not allowed\n1,2,3",
            ob_get_clean()
        );
    }

    public function testVmForeachByValueOnHookedArrayStillWorks(): void
    {
        $code = <<<'PHP'
<?php
class H {
    public array $items {
        get => $this->items;
        set => $this->items = $value;
    }
}
$h = new H;
$h->items = [1, 2, 3];
foreach ($h->items as $v) {
    echo $v, ',';
}
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'foreach_byvalue_hooked.php');
        ob_start();
        $rt->run($block);
        self::assertSame('1,2,3,', ob_get_clean());
    }

    public function testVmForeachByRefOnByRefGetHookMutatesBacking(): void
    {
        $code = <<<'PHP'
<?php
class G {
    private array $_items = [1, 2, 3];
    public array $items {
        &get => $this->_items;
    }
}
$g = new G;
foreach ($g->items as &$v) {
    $v *= 10;
}
unset($v);
echo implode(',', $g->items);
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'foreach_byref_get_hook.php');
        ob_start();
        $rt->run($block);
        self::assertSame('10,20,30', ob_get_clean());
    }
}
