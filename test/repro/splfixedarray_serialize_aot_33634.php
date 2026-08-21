<?php
// AOT: serialize(SplFixedArray) encodes __spl_ht elements (#33634).
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
