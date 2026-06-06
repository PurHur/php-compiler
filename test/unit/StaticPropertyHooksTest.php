<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Static property hooks VM path (#4751, #6624). */
final class StaticPropertyHooksTest extends TestCase
{
    public function testDirectClassStaticPropertyHooks(): void
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
Box::$label = 'hi';
echo Box::$label;
PHP;
        $path = sys_get_temp_dir().'/static_property_hooks_'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($path, $src);
        try {
            $rt = new Runtime();
            ob_start();
            $rt->run($rt->parseAndCompile($src, $path));
            self::assertSame('hi', ob_get_clean());
        } finally {
            @unlink($path);
        }
    }

    public function testTraitMergedStaticPropertyHooks(): void
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
C::$x = 'hi';
echo C::$x;
PHP;
        $path = sys_get_temp_dir().'/trait_static_property_hooks_'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($path, $src);
        try {
            $rt = new Runtime();
            ob_start();
            $rt->run($rt->parseAndCompile($src, $path));
            self::assertSame('hi', ob_get_clean());
        } finally {
            @unlink($path);
        }
    }
}
