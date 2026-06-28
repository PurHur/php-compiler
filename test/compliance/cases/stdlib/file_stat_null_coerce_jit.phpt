--TEST--
stdlib file/path builtins JIT — null filename coerces to empty string (#13354)
--FILE--
<?php
$fail = 0;
if (false !== file_exists(null)) {
    ++$fail;
}
if (false !== is_file(null)) {
    ++$fail;
}
if (false !== is_dir(null)) {
    ++$fail;
}
if (false !== filesize(null)) {
    ++$fail;
}
if ('' !== basename(null)) {
    ++$fail;
}
if ('' !== dirname(null)) {
    ++$fail;
}
echo 0 === $fail ? "ok\n" : "fail\n";
--EXPECTF--
PHP Warning:  filesize(): stat failed for  in %s on line %d
ok
