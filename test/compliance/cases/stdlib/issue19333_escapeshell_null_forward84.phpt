--TEST--
stdlib escapeshellarg/escapeshellcmd null — TypeError on 8.4 forward profile (#19333, ext/standard/exec.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$cases = [
    'escapeshellarg' => fn () => escapeshellarg(null),
    'escapeshellcmd' => fn () => escapeshellcmd(null),
];
foreach ($cases as $name => $fn) {
    try {
        $fn();
        echo "{$name}: uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
echo var_export(escapeshellarg(''), true), "\n";
echo var_export(escapeshellcmd(''), true), "\n";
?>
--EXPECT--
escapeshellarg(): Argument #1 ($arg) must be of type string, null given
escapeshellcmd(): Argument #1 ($command) must be of type string, null given
'\'\''
''
