--TEST--
stdlib array_rand() — named num: matches positional under mt_srand (#14271, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$ok = 1;
foreach ([12345, 42, 999, 1] as $seed) {
    mt_srand($seed);
    $named = array_rand([1, 2, 3], num: 2);
    mt_srand($seed);
    $pos = array_rand([1, 2, 3], 2);
    if ($named != $pos) {
        $ok = 0;
        break;
    }
}
echo $ok, "\n";
--EXPECT--
1
