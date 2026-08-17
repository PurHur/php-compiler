--TEST--
Language: typed by-ref param zend_verify_arg_type in-place coerce / TypeError (#31882, zend_execute.c)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    if (E_DEPRECATED === $no) {
        echo 'DEP:', $str, "\n";
        return true;
    }
    return false;
});

function t(int &$x): void
{
    $x++;
}

function dump(string $label, $v): void
{
    echo $label, ':', gettype($v), ':', var_export($v, true), "\n";
}

$a = 1;
t($a);
dump('int', $a);

$b = '1';
t($b);
dump('numeric-str', $b);

$c = 's';
try {
    t($c);
    echo "s-ok\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}

$d = 1.5;
t($d);
dump('float', $d);

$e = null;
try {
    t($e);
    echo "null-ok\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}

$f = true;
t($f);
dump('bool', $f);
?>
--EXPECTF--
int:integer:2
numeric-str:integer:2
t(): Argument #1 ($x) must be of type int, string given, called in %s on line %d
DEP:Implicit conversion from float 1.5 to int loses precision
float:integer:2
t(): Argument #1 ($x) must be of type int, null given, called in %s on line %d
bool:integer:2
