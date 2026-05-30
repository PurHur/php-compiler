--TEST--
stdlib PHP_* core predefined constants JIT (issue #3576)
--FILE--
<?php
echo PHP_INT_MAX > 0 ? "int_max_ok\n" : "int_max_bad\n";
echo is_string(PHP_EOL) ? "eol_ok\n" : "eol_bad\n";
echo is_string(PHP_VERSION) && PHP_VERSION !== '' ? "version_ok\n" : "version_bad\n";
$core = get_defined_constants(true);
$definedOk = isset($core['Core']['PHP_INT_MAX']);
if ($definedOk) {
    $definedOk = isset($core['Core']['PHP_EOL']);
}
if ($definedOk) {
    $definedOk = isset($core['Core']['PHP_VERSION']);
}
echo $definedOk ? "defined_ok\n" : "defined_bad\n";
echo PHP_SAPI, "\n";
--EXPECT--
int_max_ok
eol_ok
version_ok
defined_ok
cli
