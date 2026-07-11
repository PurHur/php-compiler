--TEST--
Language: PHP 8.3 dynamic class constant fetch Class::{$name} (#5923, zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsDynamicClassConstFetch()) {
    die('skip dynamic class const fetch disabled on reference profile');
}
?>
--FILE--
<?php
class C {
    public const X = 1;
}
$name = 'X';
var_dump(C::{$name});
echo "\n";
$cls = 'C';
var_dump($cls::{$name});
echo "\n";

enum E: int { case A = 1; }
class D { public const E = E::A; }
$en = 'E';
var_dump(D::{$en});
echo "\n";

$bad = 'MISSING';
try {
    C::{$bad};
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
int(1)

int(1)

enum(E::A)

Error: Undefined constant C::MISSING
