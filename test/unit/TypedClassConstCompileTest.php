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
            $this->markTestSkipped('typed class constants require CompilerVersion 8.4.0+');
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
