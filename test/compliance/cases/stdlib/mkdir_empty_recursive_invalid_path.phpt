--TEST--
stdlib mkdir("", …, true) warns Invalid path (#29359)
--FILE--
<?php
$got = [];
set_error_handler(static function (int $errno, string $errstr) use (&$got): bool {
    $got[] = $errstr;
    return true;
});
$a = mkdir('', 0777, false);
$b = mkdir('', 0777, true);
restore_error_handler();
echo var_export($a, true), "\n";
echo var_export($b, true), "\n";
echo ($got[0] ?? ''), "\n";
echo ($got[1] ?? ''), "\n";
?>
--EXPECT--
false
false
mkdir(): No such file or directory
mkdir(): Invalid path
