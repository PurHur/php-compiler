--TEST--
hexdec/octdec/bindec/base_convert invalid digits E_DEPRECATED (JIT, #24950)
--FILE--
<?php
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    $seen[] = [$no, $str];
    return true;
});
echo hexdec('1g'), ',', octdec('18'), ',', bindec('102'), ',', base_convert('1g', 16, 10), "\n";
echo json_encode($seen), "\n";
--EXPECT--
1,1,2,1
[[8192,"Invalid characters passed for attempted conversion, these have been ignored"],[8192,"Invalid characters passed for attempted conversion, these have been ignored"],[8192,"Invalid characters passed for attempted conversion, these have been ignored"],[8192,"Invalid characters passed for attempted conversion, these have been ignored"]]
