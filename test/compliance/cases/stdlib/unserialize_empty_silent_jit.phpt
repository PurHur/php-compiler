--TEST--
stdlib unserialize('') / unserialize(null) — empty payload silent false JIT (#29483)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$msgs = [];
set_error_handler(static function (int $no, string $str) use (&$msgs): bool {
    $msgs[] = $str;
    return true;
});
// Runtime vars so NestedJIT/AOT do not fold away the empty/fail paths (#29483).
$empty = '';
$bad = 'x';
var_export(unserialize($empty));
echo "\n";
echo 'empty_warn=', (int) (0 !== count(array_filter($msgs, static fn ($m) => str_contains($m, 'Error at offset')))), "\n";
$msgs = [];
var_export(unserialize(null));
echo "\n";
$hasDep = false;
$hasOffset = false;
foreach ($msgs as $m) {
    if (str_contains($m, 'Passing null to parameter #1 ($data)')) {
        $hasDep = true;
    }
    if (str_contains($m, 'Error at offset')) {
        $hasOffset = true;
    }
}
echo 'null_dep=', (int) $hasDep, ' null_offset=', (int) $hasOffset, "\n";
$msgs = [];
var_export(unserialize($bad));
echo "\n";
echo 'bad_offset=', (int) (0 !== count(array_filter($msgs, static fn ($m) => str_contains($m, 'Error at offset')))), "\n";
--EXPECT--
false
empty_warn=0
false
null_dep=1 null_offset=0
false
bad_offset=1
