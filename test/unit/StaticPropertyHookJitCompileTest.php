<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Static property hooks compile through Runtime (#6931). */
final class StaticPropertyHookJitCompileTest extends TestCase
{
    public function testStaticPropertyHooksCompile(): void
    {
        $runtime = new Runtime();
        $script = $runtime->parseAndCompile(
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
        self::assertNotNull($script);
    }
}
