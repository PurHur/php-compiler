<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Typed trait constants compile gate (#5212, #5993, Zend/zend_compile.c). */
final class TraitTypedConstCompileTest extends TestCase
{
    public function testTypedTraitConstantCompilesOn83Target(): void
    {
        if (!CompilerVersion::supportsTypedTraitConstants()) {
            $this->markTestSkipped('typed trait constants require CompilerVersion 8.3+');
        }
        $code = <<<'PHP'
<?php
trait T {
    public const string X = 'a';
}
class C {
    use T;
}
echo C::X;
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'trait_typed_const.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('a', ob_get_clean());
    }

    public function testIncompatibleTraitConstantOverrideFailsCompile(): void
    {
        if (!CompilerVersion::supportsTypedTraitConstants()) {
            $this->markTestSkipped('typed trait constants require CompilerVersion 8.3+');
        }
        $code = <<<'PHP'
<?php
trait T { public const string FOO = 'a'; }
class C {
    use T;
    public const int FOO = 1;
}
PHP;
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Type of C::FOO must be compatible with T::FOO of type string');
        $runtime->parseAndCompile($code, 'trait_typed_const_inherit_bad.php');
    }

    public function testTypedTraitConstantValueTypeMismatchFailsCompile(): void
    {
        if (!CompilerVersion::supportsTypedTraitConstants()) {
            $this->markTestSkipped('typed trait constants require CompilerVersion 8.3+');
        }
        $code = <<<'PHP'
<?php
trait TBad {
    public const int X = '1';
}
final class C {
    use TBad;
}
PHP;
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Cannot use string as value for class constant TBad::X of type int'
        );
        $runtime->parseAndCompile($code, 'trait_typed_const_mismatch.php');
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
