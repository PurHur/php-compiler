--TEST--
stdlib URL/Base64 wrong argc — ArgumentCountError not LogicException (#28316)
--FILE--
<?php
$cases = [
    'base64_encode' => static function () { base64_encode('a', 'x'); },
    'base64_decode' => static function () { base64_decode('YQ==', true, 'x'); },
    'urlencode' => static function () { urlencode('a', 'x'); },
    'urldecode' => static function () { urldecode('a', 'x'); },
    'rawurlencode' => static function () { rawurlencode('a', 'x'); },
    'rawurldecode' => static function () { rawurldecode('a', 'x'); },
];
foreach ($cases as $name => $fn) {
    try {
        $fn();
        echo $name, " ran\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
base64_encode ArgumentCountError: base64_encode() expects exactly 1 argument, 2 given
base64_decode ArgumentCountError: base64_decode() expects at most 2 arguments, 3 given
urlencode ArgumentCountError: urlencode() expects exactly 1 argument, 2 given
urldecode ArgumentCountError: urldecode() expects exactly 1 argument, 2 given
rawurlencode ArgumentCountError: rawurlencode() expects exactly 1 argument, 2 given
rawurldecode ArgumentCountError: rawurldecode() expects exactly 1 argument, 2 given
