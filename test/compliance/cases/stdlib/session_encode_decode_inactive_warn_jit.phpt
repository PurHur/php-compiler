--TEST--
stdlib session_encode()/session_decode() inactive session E_WARNING JIT (#21952)
--FILE--
<?php
$warnings = [];
set_error_handler(function ($no, $msg) use (&$warnings) {
    $warnings[] = $msg;
    return true;
});
$enc = session_encode();
$dec = session_decode('a|i:1;');
foreach ($warnings as $msg) {
    echo 'W:', $msg, "\n";
}
var_export($enc);
echo '|';
var_export($dec);
echo "\n";
--EXPECT--
W:session_encode(): Cannot encode non-existent session
W:session_decode(): Session data cannot be decoded when there is no active session
false|false
