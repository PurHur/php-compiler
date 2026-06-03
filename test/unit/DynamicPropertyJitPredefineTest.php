<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\JIT\Builtin\Type\Object_;

/**
 * JIT undeclared property predefine before `new` (#5111).
 */
final class DynamicPropertyJitPredefineTest extends TestCase
{
    public function testCollectUndeclaredWriteOnClassC(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public int $x = 1;
}
$c = new C();
$c->y = 2;
PHP;
        $block = $runtime->parseAndCompile($code, 'dyn.php');
        $this->assertNotNull($block);
        $pending = Block::collectJitUndeclaredInstancePropertyWrites($block);
        $this->assertArrayHasKey('c', $pending);
        $this->assertSame(['y'], $pending['c']);
    }

}
