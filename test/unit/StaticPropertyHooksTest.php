<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\Runtime;
use PHPCompiler\SourcePreprocessor\PropertyHooks;
use PHPUnit\Framework\TestCase;

/** Static property hooks rejected at compile time (#6619, #6901, php-src 8.4). */
final class StaticPropertyHooksTest extends TestCase
{
    public function testDirectClassStaticPropertyHooksCompileFatal(): void
    {
        $src = <<<'PHP'
<?php
class Box {
    public static string $label {
        get => self::$v;
        set => self::$v = $value;
    }
    private static ?string $v = null;
}
PHP;
        $this->expectCompileFatal($src);
    }

    public function testTraitStaticPropertyHooksCompileFatal(): void
    {
        $src = <<<'PHP'
<?php
trait T {
    public static string $x {
        get => self::$v;
        set => self::$v = $value;
    }
    private static ?string $v = null;
}
class C { use T; }
PHP;
        $this->expectCompileFatal($src);
    }

    public function testLiteralGetHookCompileFatal(): void
    {
        $src = <<<'PHP'
<?php
class C {
    public static int $x {
        get => 1;
    }
}
PHP;
        $this->expectCompileFatal($src);
    }

    private function expectCompileFatal(string $src): void
    {
        try {
            (new PropertyHooks())->process($src, 'static_hooks.php');
            self::fail('Expected CompileFatal for static property hooks');
        } catch (CompileFatal $e) {
            self::assertSame(PropertyHooks::STATIC_HOOK_COMPILE_ERROR, $e->getMessage());
        }

        $path = sys_get_temp_dir().'/static_property_hooks_'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($path, $src);
        try {
            $rt = new Runtime();
            $rt->parseAndCompile($src, $path);
            self::fail('Expected Runtime parseAndCompile to throw CompileFatal');
        } catch (CompileFatal $e) {
            self::assertSame(PropertyHooks::STATIC_HOOK_COMPILE_ERROR, $e->getMessage());
        } finally {
            @unlink($path);
        }
    }
}
