--TEST--
stdlib path/filestat builtins — null path coerces to empty string under strict_types (#13354, ext/standard/filestat.c)
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');
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
PHP Warning:  rename(,/tmp/no-such-target-13354): No such file or directory in %s on line %d
ok
