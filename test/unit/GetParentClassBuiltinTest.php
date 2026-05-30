<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** get_parent_class() VM builtin (issue #3483). */
final class GetParentClassBuiltinTest extends TestCase
{
    public function testVmGetParentClassObjectAndClassName(): void
    {
        $code = <<<'PHP'
<?php
class B {}
class C extends B {}
echo get_parent_class(new C()), "\n";
echo get_parent_class(C::class), "\n";
echo get_parent_class(B::class) ? '1' : '0';
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'get_parent_class.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("B\nB\n0", ob_get_clean());
    }
}
