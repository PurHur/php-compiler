--TEST--
stdlib Z_PARAM_STR null — soft-null mix on 8.4 JIT (#19319/#21180/#21420, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
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
str_split: uncaught array (
)
convert_uudecode: uncaught false
str_getcsv: uncaught array (
  0 => NULL,
)
ord: uncaught 0
''
