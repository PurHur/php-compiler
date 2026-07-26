--TEST--
ReflectionConstant rejects Class::CONST name — Zend globals only (#23604, php_reflection.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C23604 { public const X = 1; }

try {
    $r = new ReflectionConstant('C23604::X');
    echo 'accepted ', $r->getName(), '=', var_export($r->getValue(), true), "\n";
} catch (ReflectionException $e) {
    echo $e->getMessage(), "\n";
}

$via = (new ReflectionClass(C23604::class))->getReflectionConstant('X');
echo get_class($via), ' ', $via->getName(), '=', var_export($via->getValue(), true), "\n";

const G23604 = 9;
$g = new ReflectionConstant('G23604');
echo $g->getName(), '=', var_export($g->getValue(), true), "\n";
--EXPECT--
Constant "C23604::X" does not exist
ReflectionClassConstant X=1
G23604=9
