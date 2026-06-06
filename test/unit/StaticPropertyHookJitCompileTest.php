<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Static property hooks compile and run on VM (#4751, #6624). */
final class StaticPropertyHookJitCompileTest extends TestCase
{
    public function testStaticPropertyHooksCompileForDirectClass(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
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
        self::assertNotNull($block);
    }
}
