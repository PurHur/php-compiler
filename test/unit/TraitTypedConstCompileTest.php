<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Typed trait constants compile gate (#5212, Zend/zend_compile.c). */
final class TraitTypedConstCompileTest extends TestCase
{
    public function testTypedTraitConstantIsCompileErrorOn82Target(): void
    {
        $code = <<<'PHP'
<?php
trait T {
    public const string X = 'a';
}
PHP;
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('syntax error, unexpected identifier "X", expecting "="');
        $runtime->parseAndCompile($code, 'trait_typed_const.php');
    }

    public function testUntypedTraitConstantStillCompiles(): void
    {
        $code = <<<'PHP'
<?php
trait T {
    public const X = 'a';
}
class C {
    use T;
}
echo C::X;
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'trait_untyped_const.php');
        $this->assertNotNull($block);
    }
}
