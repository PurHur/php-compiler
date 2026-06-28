--TEST--
stdlib file/path builtins — null filename coerces to empty string (#13354, ext/standard/filestat.c)
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
$renamed = rename(null, '/tmp/no-such-target-13354');
if (false !== $renamed) {
    ++$fail;
}
$pi = pathinfo(null);
if (!\is_array($pi) || '' !== ($pi['basename'] ?? 'x') || '' !== ($pi['filename'] ?? 'x')) {
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
PHP Warning:  rename(,/tmp/no-such-target-13354): No such file or directory in %s on line %d
ok
