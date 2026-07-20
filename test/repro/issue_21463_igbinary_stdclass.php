<?php

declare(strict_types=1);

$o = new stdClass();
$o->a = 1;
$o->b = 'x';
$bin = igbinary_serialize($o);
$u = igbinary_unserialize($bin);
if (!($u instanceof stdClass)) {
    echo "fail: not stdClass\n";
    exit(1);
}
echo 'a=', $u->a, "\n";
echo 'b=', $u->b, "\n";

// Zend-compatible fixture: object8 "stdClass" + array8 one prop a=>1
$fixture = hex2bin('000000021708737464436c61737314011101610601');
$fromZend = igbinary_unserialize($fixture);
if (!($fromZend instanceof stdClass) || 1 !== $fromZend->a) {
    echo "fail: fixture decode\n";
    var_export($fromZend);
    echo "\n";
    exit(1);
}
echo "fixture=ok\n";

$arr = ['o' => $o];
$nested = igbinary_unserialize(igbinary_serialize($arr));
echo 'nested=', $nested['o']->b, "\n";

echo "ok\n";
