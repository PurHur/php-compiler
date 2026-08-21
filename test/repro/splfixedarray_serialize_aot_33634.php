<?php
// AOT: serialize(SplFixedArray) encodes __spl_ht elements (#33634 / #33639).
$a = new SplFixedArray(2);
$a[0] = 1;
$a[1] = 2;
echo 'packed=', serialize($a), "\n";

$b = SplFixedArray::fromArray([10, 20, 30]);
echo 'from=', serialize($b), "\n";

$e = new SplFixedArray(0);
echo 'empty=', serialize($e), "\n";

$s = new SplFixedArray(1);
$s[0] = 'hi';
echo 'str=', serialize($s), "\n";

// Null holes must appear as i:k;N; (php-src walks size; NestedJIT exportKeyValuePairs skips null) (#33639).
$h = new SplFixedArray(3);
$h[0] = 10;
$h[2] = 'x';
echo 'holes=', serialize($h), "\n";

$t = new SplFixedArray(3);
$t[0] = 1;
echo 'trail=', serialize($t), "\n";
