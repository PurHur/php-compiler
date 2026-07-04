--TEST--
stdlib array_walk() — (object) array cast operand without by-ref notice (#15874, ext/standard/array.c)
--FILE--
<?php
$expectedNotice = 'Only variables should be passed by reference';
ob_start();
array_walk((object) ['x' => 1], static fn ($v) => print($v));
$out = ob_get_clean();
$last = error_get_last();
echo 'out: ', $out, "\n";
echo 'notice: ', (null !== $last && str_contains($last['message'], $expectedNotice)) ? 'yes' : 'no', "\n";
?>
--EXPECT--
out: 1
notice: no
