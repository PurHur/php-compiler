<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Compiler\CompileFatal;
use PHPUnit\Framework\TestCase;

/** Static property hooks rejected at compile time (#6619, #6901). */
final class StaticPropertyHookJitCompileTest extends TestCase
{
    public function testStaticPropertyHooksRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(SourcePreprocessor\PropertyHooks::STATIC_HOOK_COMPILE_ERROR);
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
