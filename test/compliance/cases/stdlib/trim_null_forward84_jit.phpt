--TEST--
stdlib trim()/ltrim()/rtrim()/chop() null — DEP+coerce on 8.4 forward profile JIT (#21404, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    echo "DEP:{$msg}\n";
    return true;
});
foreach (['trim', 'ltrim', 'rtrim', 'chop'] as $fn) {
    $result = $fn(null);
    echo "{$fn}:[{$result}]\n";
}
echo var_export(trim(''), true), "\n";
?>
--EXPECT--
DEP:trim(): Passing null to parameter #1 ($string) of type string is deprecated
trim:[]
DEP:ltrim(): Passing null to parameter #1 ($string) of type string is deprecated
ltrim:[]
DEP:rtrim(): Passing null to parameter #1 ($string) of type string is deprecated
rtrim:[]
DEP:chop(): Passing null to parameter #1 ($string) of type string is deprecated
chop:[]
''
