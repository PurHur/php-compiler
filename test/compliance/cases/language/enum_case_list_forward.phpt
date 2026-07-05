--TEST--
Language: PHP 8.5 enum case list syntax — case A, B, C; (#5479, #16665)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsEnumCaseList()) {
    die('skip enum case list syntax disabled on reference profile');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
enum E {
    case A, B, C;
}
echo E::A->name, E::C->name, "\n";
$cases = E::cases();
echo count($cases), "\n";
echo $cases[0]->name, $cases[1]->name, $cases[2]->name, "\n";

enum Color: string {
    case Red = 'r', Blue = 'b';
}
echo Color::Red->value, Color::Blue->value, "\n";
--EXPECT--
AC
3
ABC
rb
