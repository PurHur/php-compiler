--TEST--
bindec/hexdec/octdec/decbin/dechex/decoct Reflection + named args (VM, issue #24788)
--FILE--
<?php
$expect = [
    'bindec' => 'binary_string',
    'hexdec' => 'hex_string',
    'octdec' => 'octal_string',
    'decbin' => 'num',
    'dechex' => 'num',
    'decoct' => 'num',
];
foreach ($expect as $fn => $param) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $fn, ':', implode(',', $names), "\n";
}

echo 'bindec=', bindec(binary_string: '1010'), "\n";
echo 'hexdec=', hexdec(hex_string: 'ff'), "\n";
echo 'octdec=', octdec(octal_string: '17'), "\n";
echo 'decbin=', decbin(num: 10), "\n";
echo 'dechex=', dechex(num: 255), "\n";
echo 'decoct=', decoct(num: 15), "\n";

try {
    bindec(binary_number: '1010');
    echo "legacy-ok\n";
} catch (Throwable $e) {
    echo 'legacy:', $e->getMessage(), "\n";
}
--EXPECT--
bindec:binary_string
hexdec:hex_string
octdec:octal_string
decbin:num
dechex:num
decoct:num
bindec=10
hexdec=255
octdec=15
decbin=1010
dechex=ff
decoct=17
legacy:Unknown named parameter $binary_number
