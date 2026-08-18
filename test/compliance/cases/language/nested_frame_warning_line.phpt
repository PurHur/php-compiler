--TEST--
Language: warnings inside functions/methods/closures cite inner opline not call site (#32040)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
set_error_handler(function (int $errno, string $message, string $file, int $line): bool {
    echo 'L:', $line, ' ', $message, "\n";

    return true;
});

function inner_32040(): void
{
    echo $missing_fn;
}
inner_32040();

class C_32040
{
    public function go(): void
    {
        echo $missing_m;
    }
}
(new C_32040)->go();

$fn = function () {
    echo 5.5 % 2, "\n";
};
$fn();

restore_error_handler();
error_clear_last();
$fn2 = function () {
    echo $missing_c;
};
$fn2();
$last = error_get_last();
echo 'last:', (string) ($last['line'] ?? 0), "\n";

echo 5.5 % 2, "\n";
--EXPECT--
L:12 Undefined variable $missing_fn
L:20 Undefined variable $missing_m
L:26 Implicit conversion from float 5.5 to int loses precision
1
last:33
1
