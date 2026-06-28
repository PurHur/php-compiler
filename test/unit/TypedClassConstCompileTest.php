<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Typed class constants compile gate (#12798, Zend/zend_compile.c). */
final class TypedClassConstCompileTest extends TestCase
{
    public function testTypedClassConstantRejectedOnReferenceProfile(): void
    {
        if (CompilerVersion::supportsTypedClassConstants()) {
            $this->markTestSkipped('typed class constants enabled on forward profile (#12994)');
        }
        $code = <<<'PHP'
<?php
class C {
    public const string K = 'v';
}
echo C::K;
PHP;
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('syntax error, unexpected identifier "K", expecting "="');
        $runtime->parseAndCompile($code, 'typed_class_const_reject.php');
    }

    public function testTypedClassConstantCompilesOnForwardProfile(): void
    {
        if (!CompilerVersion::supportsTypedClassConstants()) {
            $this->markTestSkipped('typed class constants require forward profile 8.3+ (#12994)');
        }
        $code = <<<'PHP'
<?php
class C {
    public const string K = 'v';
    public const array X = [1, 2];
}
echo C::K, C::X[0];
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'typed_class_const_forward.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('v1', ob_get_clean());
    }

    public function testUntypedClassConstantStillCompilesOnReferenceProfile(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public const K = 'v';
}
echo C::K;
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'untyped_class_const.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('v', ob_get_clean());
    }
}
