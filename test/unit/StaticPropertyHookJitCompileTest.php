<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\SourcePreprocessor\PropertyHooks;
use PHPCompiler\Test\Support\PropertyHookTestSkip;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/** Static property hooks rejected at compile time (#24281, Zend/zend_compile.c). */
final class StaticPropertyHookJitCompileTest extends TestCase
{
    use PropertyHookTestSkip;

    protected function setUp(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
    }

    public function testStaticPropertyHooksRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(PropertyHooks::STATIC_HOOK_COMPILE_ERROR);
        $runtime->parseAndCompile(
            <<<'PHP'
<?php
class Box {
    public static string $label {
        get => self::$v;
        set => self::$v = $value;
    }
    private static ?string $v = null;
}
PHP,
            'static_hooks_jit_compile.php'
        );
    }
}
