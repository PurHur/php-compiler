<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\PropertyHookSyntaxRejector;
use PHPCompiler\Runtime;
use PHPCompiler\SourcePreprocessor\PropertyHooks;
use PHPCompiler\Test\Support\PropertyHookTestSkip;
use PHPUnit\Framework\TestCase;

/** Static property hooks rejected on PROFILE=8.4 (#24281, Zend/zend_compile.c). */
final class StaticPropertyHooksTest extends TestCase
{
    use PropertyHookTestSkip;

    protected function setUp(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
    }

    public function testDirectClassStaticPropertyHooksRejectedByPreprocessor(): void
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
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(PropertyHooks::STATIC_HOOK_COMPILE_ERROR);
        (new PropertyHooks())->process($src, 'static_hooks.php');
    }

    public function testTraitStaticPropertyHooksRejectedByPreprocessor(): void
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
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(PropertyHooks::STATIC_HOOK_COMPILE_ERROR);
        (new PropertyHooks())->process($src, 'static_hooks.php');
    }

    public function testStaticPropertyHooksRejectedBySyntaxRejector(): void
    {
        $src = <<<'PHP'
<?php
class C {
    public static int $x {
        get => 1;
    }
}
PHP;
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(PropertyHooks::STATIC_HOOK_COMPILE_ERROR);
        PropertyHookSyntaxRejector::reject($src, 'static_hooks.php');
    }

    public function testStaticPropertyHooksRejectedOnVm(): void
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
            $this->expectException(\CompileError::class);
            $this->expectExceptionMessage(PropertyHooks::STATIC_HOOK_COMPILE_ERROR);
            $rt->parseAndCompile($src, $path);
        } finally {
            @unlink($path);
        }
    }
}
