<?php
// #23483: ++/-- guards every native long with __compiler_is_resource, because resource handles
// ARE native longs here and php-types has no resource type. IncDecResourceProvenance elides that
// guard when the value provably comes from a literal or from arithmetic.
//
// The way that elision could go wrong is by dropping the guard on something that really is a
// resource, so this keeps live handles open while incrementing integers whose values collide with
// plausible handle ids (1, 2, 3 ...), and also increments across loop phis and function scope.
$fh = fopen('php://memory', 'r+');
$fh2 = fopen('php://memory', 'r+');

$a = 1;
++$a;
$b = 2;
++$b;
$c = 3;
++$c;
$d = 4;
--$d;
echo "$a $b $c $d\n";

// loop phi: counter and accumulator both flow literal -> phi -> ++ -> phi
function counted(): int
{
    $acc = 0;
    for ($i = 0; $i < 5; ++$i) {
        ++$acc;
    }

    return $acc;
}
echo counted(), "\n";

// arithmetic provenance, post/pre forms, and a decrement below zero
$x = 10 - 7;
$x++;
$y = 2 * 3;
$y--;
echo "$x $y\n";

$n = 0;
$n--;
$n--;
echo $n, "\n";

// resources still usable after all that incrementing
fwrite($fh, 'alpha');
rewind($fh);
echo fread($fh, 5), "\n";
fwrite($fh2, 'beta');
rewind($fh2);
echo fread($fh2, 4), "\n";
fclose($fh);
fclose($fh2);
echo "done\n";
