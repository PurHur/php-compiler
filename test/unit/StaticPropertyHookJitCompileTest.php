<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Static property hooks are a compile error in PHP 8.4 (#6619, Zend/zend_compile.c).
 */
final class StaticPropertyHookJitCompileTest extends TestCase
{
    public function testStaticPropertyHooksFailAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot declare hooks for static property');
        $runtime->parseAndCompile(
            <<<'PHP'
<?php
class Box {
    public static string $label {
        get => 'static:' . self::$label;
        set => strtoupper($value);
    }
}
PHP,
            'static_hooks_jit_compile.php'
        );
    }
}
