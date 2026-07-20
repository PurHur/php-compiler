--TEST--
stdlib Z_PARAM_STR null — mixed soft-null/TypeError on 8.4 (#19319/#21180, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
$cases = [
    'addcslashes' => fn () => addcslashes(null, 'a'),
    'stripslashes' => fn () => stripslashes(null),
    'hebrev' => fn () => hebrev(null),
    'str_split' => fn () => str_split(null),
    'convert_uudecode' => fn () => convert_uudecode(null),
    'str_getcsv' => fn () => str_getcsv(null),
    'ord' => fn () => ord(null),
];
foreach ($cases as $name => $fn) {
    try {
        $r = $fn();
        echo "{$name}: uncaught ", var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
echo var_export(stripslashes(''), true), "\n";
?>
--EXPECT--
addcslashes: uncaught ''
stripslashes: uncaught ''
hebrev: uncaught ''
str_split(): Argument #1 ($string) must be of type string, null given
convert_uudecode(): Argument #1 ($string) must be of type string, null given
str_getcsv(): Argument #1 ($string) must be of type string, null given
ord: uncaught 0
''
