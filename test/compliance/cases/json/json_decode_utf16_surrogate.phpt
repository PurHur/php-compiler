--TEST--
json_decode UTF-16 surrogate escapes — pair OK, unpaired JSON_ERROR_UTF16 (ext/json/json_scanner.re #22821)
--FILE--
<?php
$cases = [
    '"\uD800"',
    '"\uDFFF"',
    '"\uDC00"',
    '"\uD800\uDC00"',
    '"\uD800\uD800"',
    '"a\uD800b"',
    '{"\uD800":1}',
];
foreach ($cases as $j) {
    $r = json_decode($j);
    echo $j, ' => ';
    if (null === $r && JSON_ERROR_NONE !== json_last_error()) {
        echo 'null err=', json_last_error(), ' ', json_last_error_msg(), "\n";
    } else {
        var_export($r);
        echo ' err=', json_last_error(), ' ', json_last_error_msg(), "\n";
    }
}
--EXPECT--
"\uD800" => null err=10 Single unpaired UTF-16 surrogate in unicode escape
"\uDFFF" => null err=10 Single unpaired UTF-16 surrogate in unicode escape
"\uDC00" => null err=10 Single unpaired UTF-16 surrogate in unicode escape
"\uD800\uDC00" => '𐀀' err=0 No error
"\uD800\uD800" => null err=10 Single unpaired UTF-16 surrogate in unicode escape
"a\uD800b" => null err=10 Single unpaired UTF-16 surrogate in unicode escape
{"\uD800":1} => null err=10 Single unpaired UTF-16 surrogate in unicode escape
