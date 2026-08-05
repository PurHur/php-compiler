<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** get_parent_class() VM builtin (issue #3483). */
final class GetParentClassBuiltinTest extends TestCase
{
    public function testVmGetParentClassObjectAndClassName(): void
    {
        $code = <<<'PHP'
<?php
class B {}
class C extends B {}
echo get_parent_class(new C()), "\n";
echo get_parent_class(C::class), "\n";
echo get_parent_class(B::class) ? '1' : '0';
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'get_parent_class.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("B\nB\n0", ob_get_clean());
    }

    public function testVmGetParentClassExtraArgsArgumentCountError(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $code = <<<'PHP'
<?php
try {
    get_parent_class('stdClass', true);
} catch (ArgumentCountError $e) {
    echo get_class($e), ': ', $e->getMessage();
}
PHP;
            $rt = new Runtime();
            $block = $rt->parseAndCompile($code, 'get_parent_class_argc.php');
            ob_start();
            $rt->run($block);
            $this->assertSame(
                'ArgumentCountError: get_parent_class() expects at most 1 argument, 2 given',
                ob_get_clean()
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVmGetParentClassReflectionArityUnderProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $code = <<<'PHP'
<?php
$r = new ReflectionFunction('get_parent_class');
echo $r->getNumberOfParameters(), "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName(), "\n";
}
PHP;
            $rt = new Runtime();
            $block = $rt->parseAndCompile($code, 'get_parent_class_refl.php');
            ob_start();
            $rt->run($block);
            $this->assertSame("1\nobject_or_class\n", ob_get_clean());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** Zend stubs: object|string → string|false; spl_autoload_functions → array (#27902). */
    public function testVmGetParentClassAndSplAutoloadFunctionsReflectionTypes(): void
    {
        $code = <<<'PHP'
<?php
foreach (['get_parent_class', 'spl_autoload_functions'] as $f) {
    $rf = new ReflectionFunction($f);
    $ret = $rf->getReturnType();
    echo $f, ' ret=', $ret ? (string) $ret : '(none)', "\n";
    foreach ($rf->getParameters() as $p) {
        $t = $p->getType();
        echo '  ', ($t ? (string) $t.' ' : ''), '$', $p->getName(), "\n";
    }
}
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'get_parent_class_spl_refl.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "get_parent_class ret=string|false\n"
            ."  object|string \$object_or_class\n"
            ."spl_autoload_functions ret=array\n",
            ob_get_clean()
        );
    }

    public function testVmGetParentClassEnumCaseReturnsFalse(): void
    {
        $code = <<<'PHP'
<?php
enum E: string { case A = 'x'; }
var_export(get_parent_class(E::A));
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'get_parent_class_enum.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('false', ob_get_clean());
    }

    public function testVmGetParentClassZeroArgMatchesZendUnderProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $code = <<<'PHP'
<?php
set_error_handler(static function (int $n, string $s): bool {
    echo 'DEP:', $s, "\n";
    return true;
});
class P {}
class C extends P {
    public function f() { return get_parent_class(); }
}
echo (new C)->f(), "\n";
class D {
    public function g() { return get_class(); }
}
echo (new D)->g(), "\n";
PHP;
            $rt = new Runtime();
            $block = $rt->parseAndCompile($code, 'get_parent_class_zero_arg.php');
            ob_start();
            $rt->run($block);
            $this->assertSame(
                "DEP:Calling get_parent_class() without arguments is deprecated\n"
                ."P\n"
                ."DEP:Calling get_class() without arguments is deprecated\n"
                ."D\n",
                ob_get_clean()
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
