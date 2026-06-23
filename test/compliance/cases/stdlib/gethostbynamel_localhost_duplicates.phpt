--TEST--
stdlib gethostbynamel() localhost preserves duplicate A records (issue #10713)
--SKIPIF--
<?php
if (!function_exists('gethostbynamel')) {
    echo "skip\n";
    exit;
}
$zend = gethostbynamel('localhost');
if ($zend === false || count($zend) < 2) {
    echo "skip\n";
}
?>
--FILE--
<?php
$ips = gethostbynamel('localhost');
echo is_array($ips) ? "array\n" : "not-array\n";
echo is_array($ips) ? (string)count($ips) . "\n" : "0\n";
echo is_array($ips) && count($ips) === 2 && $ips[0] === $ips[1] ? "dup\n" : "no-dup\n";
--EXPECT--
array
2
dup
