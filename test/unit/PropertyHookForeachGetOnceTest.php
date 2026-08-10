<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\Test\Support\PropertyHookTestSkip;
use PHPUnit\Framework\TestCase;

/**
 * foreach on objects with backed get hooks must invoke get once per property (#29702).
 *
 * php-src: Zend/zend_property_hooks.c / zend_object_handlers.c — FE_FETCH reads once.
 */
final class PropertyHookForeachGetOnceTest extends TestCase
{
    use PropertyHookTestSkip;

    protected function setUp(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
    }

    public function testVmForeachInvokesGetHookOnce(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public string $x {
        get { echo "GET\n"; return "v"; }
        set { $this->x = $value; }
    }
    public int $y = 2;
}
$o = new C();
$o->x = "a";
foreach ($o as $k => $v) {
    echo "BODY $k=$v\n";
}
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'foreach_hook_get_once.php');
        ob_start();
        $rt->run($block);
        self::assertSame(
            "GET\nBODY x=v\nBODY y=2\n",
            ob_get_clean()
        );
    }

    public function testVmForeachVirtualGetOnlyInvokesOnce(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public string $x {
        get { echo "GET\n"; return "v"; }
    }
}
$o = new C();
foreach ($o as $k => $v) {
    echo "BODY $k=$v\n";
}
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'foreach_hook_virtual_get_once.php');
        ob_start();
        $rt->run($block);
        self::assertSame(
            "GET\nBODY x=v\n",
            ob_get_clean()
        );
    }
}
