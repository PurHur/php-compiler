--TEST--
stdlib opendir()/mkdir()/rmdir()/chdir() null JIT — coerce to empty path, false (#18673)
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');
$fail = 0;
if (false !== @opendir(null)) {
    ++$fail;
}
if (false !== @mkdir(null)) {
    ++$fail;
}
if (false !== @rmdir(null)) {
    ++$fail;
}
if (false !== @chdir(null)) {
    ++$fail;
}
echo 0 === $fail ? "ok\n" : "fail\n";
--EXPECT--
ok
