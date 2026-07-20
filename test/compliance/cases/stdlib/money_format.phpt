--TEST--
ext/standard money_format() strfmon parity (#3693, ext/standard/formatted_print.c)
--SKIPIF--
<?php
if (!extension_loaded('FFI') && !function_exists('money_format')) {
    echo 'skip no strfmon/money_format';
}
?>
--FILE--
<?php
declare(strict_types=1);

if (!function_exists('money_format') && !class_exists('FFI')) {
    echo "skip\n";
    exit;
}

setlocale(LC_MONETARY, 'C');
$ok = money_format('%i', 1234.56);
echo 'fmt_i=', ($ok !== false && str_contains((string) $ok, '1234')) ? 'Y' : 'N', "\n";

$bad = @money_format('%^', 1.0);
echo 'bad=', ($bad === false) ? 'Y' : 'N', "\n";
?>
--EXPECT--
fmt_i=Y
bad=Y
