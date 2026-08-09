--TEST--
stdlib chroot("") warns No such file or directory (errno 2) (#29360)
--FILE--
<?php
$got = [];
set_error_handler(static function (int $errno, string $errstr) use (&$got): bool {
    $got[] = $errstr;
    return true;
});
$r = chroot('');
restore_error_handler();
echo var_export($r, true), "\n";
echo ($got[0] ?? ''), "\n";
echo count($got), "\n";
?>
--EXPECT--
false
chroot(): No such file or directory (errno 2)
1
