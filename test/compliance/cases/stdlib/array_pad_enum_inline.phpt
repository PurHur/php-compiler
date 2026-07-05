--TEST--
stdlib array_pad() inline enum haystack + pad value (#8883, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
enum E: int { case A = 1; case B = 2; }
$result = array_pad([E::A], 3, E::B);
foreach ($result as $v) {
    if (!$v instanceof E) {
        echo 'fail';
        exit(1);
    }
}
echo count($result) === 3 && $result[0] === E::A && $result[1] === E::B ? 'ok' : 'fail';
--EXPECT--
ok
