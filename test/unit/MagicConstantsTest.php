<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * OOP magic constants lowered at parse time (#199).
 */
final class MagicConstantsTest extends TestCase
{
    public function testClassAndFunctionInMethod(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public function id(): string {
        return __CLASS__ . '::' . __FUNCTION__;
    }
}
echo (new C)->id();
PHP;
        $this->assertSame('C::id', $this->runVm($code));
    }

    public function testFunctionAndMethodInsideClosures(): void
    {
        $code = <<<'PHP'
<?php
$free = (function () {
    return __FUNCTION__ . '|' . __METHOD__;
})();
$arrow = (fn () => __FUNCTION__ . '|' . __METHOD__)();
class C {
    public function m(): string {
        $inner = function () {
            return __FUNCTION__ . '|' . __METHOD__ . '|' . __CLASS__;
        };
        return $inner();
    }
}
echo $free, "\n", $arrow, "\n", (new C)->m(), "\n";
PHP;
        $this->assertSame("{closure}|{closure}\n{closure}|{closure}\n{closure}|{closure}|C\n", $this->runVm($code));
    }

    public function testPropertyMagicConstInHook(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public string $p {
        get => __PROPERTY__;
    }
}
echo (new C)->p;
PHP;
        $this->assertSame('p', $this->runVm($code));
    }

    public function testPropertyMagicConstOutsideHookScope(): void
    {
        $code = <<<'PHP'
<?php
class P {
    public string $foo = '';
    public function m(): string {
        return __PROPERTY__;
    }
}
echo (new P)->m();
PHP;
        if (CompilerVersion::supportsPropertyHooks()) {
            $this->expectCompileError($code, 'Cannot use __PROPERTY__ outside of a property hook');

            return;
        }

        $runtime = new Runtime(Runtime::MODE_NORMAL);
        $block = $runtime->parseAndCompile($code, 'magic-constants-test.php');
        $this->assertNotNull($block);
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Undefined constant "__PROPERTY__"');
        ob_start();
        try {
            $runtime->run($block);
        } finally {
            ob_end_clean();
        }
    }

    public function testPropertyMagicConstTopLevelOutsideHookOn84Profile(): void
    {
        if (!CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('requires PHP_COMPILER_PROFILE=8.4 property hooks gate');
        }

        $this->expectCompileError(
            "<?php\necho __PROPERTY__, \"\\n\";",
            'Cannot use __PROPERTY__ outside of a property hook'
        );
    }

    public function testFunctionMagicConstInClassConst(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public const X = __CLASS__ . '::' . __FUNCTION__;
}
echo C::X;
PHP;
        $this->assertSame('C::', $this->runVm($code));
    }

    public function testMethodMagicConstInClassConst(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public const X = __METHOD__;
}
echo C::X;
PHP;
        $this->assertSame('', $this->runVm($code));
    }

    public function testScriptDirAndFileMagicConstantsCompileAndRun(): void
    {
        $code = <<<'PHP'
<?php
$dir = __DIR__;
$file = __FILE__;
echo $dir, "\n", $file, "\n";
PHP;
        $runtime = new Runtime(Runtime::MODE_NORMAL);
        $script = '/tmp/example/magic_dir_file_probe.php';
        $block = $runtime->parseAndCompile($code, $script);
        $this->assertNotNull($block);

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();

        $this->assertSame("/tmp/example\n/tmp/example/magic_dir_file_probe.php\n", is_string($out) ? $out : '');
    }

    public function testNamespaceAndClass(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Web;

echo __NAMESPACE__, "\n";

class Home {
    public function fqcn(): string {
        return __CLASS__;
    }
}

echo (new Home)->fqcn(), "\n";
PHP;
        $this->assertSame("App\Web\nApp\Web\Home\n", $this->runVm($code));
    }

    public function testClassMagicConstInGlobalScope(): void
    {
        $code = <<<'PHP'
<?php
$class = __CLASS__;
echo $class === '' ? "ok" : "fail";
PHP;
        $this->assertSame('ok', $this->runVm($code));
    }

    /** @covers issue #26459 — __CLASS__ in trait methods is the using class. */
    public function testClassMagicConstInsideTraitIsUsingClass(): void
    {
        $code = <<<'PHP'
<?php
trait T {
    public function f() { return __CLASS__; }
    public static function s() { return __CLASS__; }
    public function m() {
        $inner = function () { return __CLASS__; };
        return $inner();
    }
    public function meta() { return __TRAIT__ . '|' . __METHOD__; }
}
class C { use T; }
class D { use T; }
echo (new C)->f(), ',', (new D)->f(), "\n";
echo 'static=', C::s(), ',', D::s(), "\n";
echo 'closure=', (new C)->m(), "\n";
echo 'meta=', (new C)->meta(), "\n";
PHP;
        $this->assertSame("C,D\nstatic=C,D\nclosure=C\nmeta=T|T::meta\n", $this->runVm($code));
    }

    private function runVm(string $code): string
    {
        $runtime = new Runtime(Runtime::MODE_NORMAL);
        $block = $runtime->parseAndCompile($code, 'magic-constants-test.php');
        $this->assertNotNull($block);

        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();

        return is_string($out) ? $out : '';
    }

    private function expectCompileError(string $code, string $message): void
    {
        $previous = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime(Runtime::MODE_NORMAL);
            $this->expectException(\CompileError::class);
            $this->expectExceptionMessage($message);
            $runtime->parseAndCompile($code, 'magic-constants-test.php');
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$previous);
            }
        }
    }
}
