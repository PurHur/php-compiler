--TEST--
simplexml_load_string() malformed XML emits Entity + snippet + caret warnings (#31183, ext/libxml/libxml.c)
--FILE--
<?php
error_reporting(E_ALL);
libxml_use_internal_errors(false);
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    if ($severity === E_WARNING) {
        $warnings[] = $message;
    }

    return true;
});
$result = simplexml_load_string('<');
restore_error_handler();
var_export($result === false);
echo "\n";
echo count($warnings), "\n";
echo ($warnings[0] ?? ''), "\n";
echo ($warnings[1] ?? ''), "\n";
echo ($warnings[2] ?? ''), "\n";

libxml_use_internal_errors(true);
libxml_clear_errors();
$sx = simplexml_load_string('<');
var_export($sx === false);
echo "\n";
echo count(libxml_get_errors()), "\n";
--EXPECT--
true
3
simplexml_load_string(): Entity: line 1: parser error : StartTag: invalid element name
simplexml_load_string(): <
simplexml_load_string():  ^
true
1
