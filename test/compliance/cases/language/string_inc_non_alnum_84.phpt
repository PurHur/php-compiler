--TEST--
language ++/-- on empty and non-alphanumeric strings (PHP 8.4, #29658)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    if ($errno === E_DEPRECATED) {
        echo "DEP:$errstr\n";
        return true;
    }
    return false;
});

$cases = ['', ' ', 'a-', 'Z', '12', 'hello', '-cc'];
foreach ($cases as $s0) {
    $s = $s0;
    $s++;
    echo 'inc ', var_export($s0, true), ' => ', var_export($s, true), "\n";
}

$d = '';
$d--;
echo 'dec empty => ', var_export($d, true), "\n";

$e = ' ';
$e--;
echo 'dec space => ', var_export($e, true), "\n";
--EXPECT--
DEP:Increment on non-alphanumeric string is deprecated
inc '' => '1'
DEP:Increment on non-alphanumeric string is deprecated
inc ' ' => ' '
DEP:Increment on non-alphanumeric string is deprecated
inc 'a-' => 'a-'
inc 'Z' => 'AA'
inc '12' => 13
inc 'hello' => 'hellp'
DEP:Increment on non-alphanumeric string is deprecated
inc '-cc' => '-cd'
DEP:Decrement on empty string is deprecated as non-numeric
dec empty => -1
DEP:Decrement on non-numeric string has no effect and is deprecated
dec space => ' '
