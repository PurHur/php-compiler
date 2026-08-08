--TEST--
stdlib strtr() nested array replace value → "Array" (#28978)
--FILE--
<?php
error_reporting(E_ALL);
$warns = [];
set_error_handler(static function (int $no, string $msg) use (&$warns): bool {
    $warns[] = $no . ':' . $msg;
    return true;
});
echo strtr('hi', ['h' => 'H', 'u' => ['x']]), "\n";
echo 'unused=', json_encode($warns), "\n";
$warns = [];
echo strtr('hi', ['h' => ['x']]), "\n";
echo 'used=', json_encode($warns), "\n";
--EXPECT--
Hi
unused=[]
Arrayi
used=["2:Array to string conversion"]
