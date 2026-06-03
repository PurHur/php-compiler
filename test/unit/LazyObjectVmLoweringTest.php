<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Block;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #4685, #4823 */
final class LazyObjectVmLoweringTest extends TestCase
{
    public function testRequiresVmLoweringForLazyProxyScript(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Svc {
    public function __construct(public string $id = '') {}
}
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyProxy(static fn () => new Svc('x'));
echo $lazy->id;
PHP;
        $block = $runtime->parseAndCompile($code, 'lazy_vm_lowering.php');
        $this->assertNotNull($block);
        $this->assertFalse(Block::requiresVmLowering($block));
        $this->assertTrue(Block::containsLazyObjectOpcodes($block));
    }

    public function testPlainClassDoesNotRequireVmLoweringForLazy(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Svc {
    public function __construct(public string $id = '') {}
}
$o = new Svc('x');
echo $o->id;
PHP;
        $block = $runtime->parseAndCompile($code, 'no_lazy.php');
        $this->assertNotNull($block);
        $this->assertFalse(Block::containsLazyObjectOpcodes($block));
    }
}
