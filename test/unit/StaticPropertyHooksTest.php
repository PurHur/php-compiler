<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\SourcePreprocessor\PropertyHooks;
use PHPUnit\Framework\TestCase;

/** Static property hooks compile and lower (#6931, PHP 8.4 zend_property_hooks.c). */
final class StaticPropertyHooksTest extends TestCase
{
    public function testDirectClassStaticPropertyHooksLower(): void
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
        [$out, $registry] = (new PropertyHooks())->process($src, 'static_hooks.php');
        self::assertStringContainsString('public static string $label;', $out);
        self::assertStringContainsString('public static function __phpc_property_get_label(): string', $out);
        self::assertStringContainsString('public static function __phpc_property_set_label(string $value): void', $out);
        self::assertTrue($registry['box']['label']['static'] ?? false);
        self::assertSame('__phpc_property_get_label', $registry['box']['label']['get'] ?? null);
        self::assertSame('__phpc_property_set_label', $registry['box']['label']['set'] ?? null);
    }

    public function testTraitStaticPropertyHooksLower(): void
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
        [$out, $registry] = (new PropertyHooks())->process($src, 'static_hooks.php');
        self::assertStringContainsString('public static string $x;', $out);
        self::assertTrue($registry['t']['x']['static'] ?? false);
    }

    public function testLiteralGetHookCompilesOnVm(): void
    {
        $src = <<<'PHP'
<?php
class C {
    public static int $x {
        get => 1;
    }
}
echo C::$x;
PHP;
        $path = sys_get_temp_dir().'/static_property_hooks_'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($path, $src);
        try {
            $rt = new Runtime();
            ob_start();
            $rt->run($rt->parseAndCompile($src, $path));
            self::assertSame('1', ob_get_clean());
        } finally {
            @unlink($path);
        }
    }

    public function testSelfReferentialStaticHookDispatchOnVm(): void
    {
        $src = <<<'PHP'
<?php
class C {
    public static int $x {
        get => self::$x + 1;
        set => self::$x = $value - 1;
    }
}
C::$x = 10;
echo C::$x;
PHP;
        $path = sys_get_temp_dir().'/static_property_hooks_self_ref_'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($path, $src);
        try {
            $rt = new Runtime();
            ob_start();
            $rt->run($rt->parseAndCompile($src, $path));
            self::assertSame('10', ob_get_clean());
        } finally {
            @unlink($path);
        }
    }
}
