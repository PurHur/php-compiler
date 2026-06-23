--TEST--
stdlib tempnam() empty prefix creates path (php-src file.c, #10835)
--FILE--
<?php
$p = tempnam(sys_get_temp_dir(), '');
echo is_string($p) ? "ok\n" : "fail\n";
if (is_string($p)) {
    @unlink($p);
}
--EXPECT--
ok
