--TEST--
SPL SplFixedArray isset/offsetExists null slots (#24255, ext/spl/spl_fixedarray.c)
--FILE--
<?php
$a = new SplFixedArray(2);
$a[0] = null;
echo isset($a[0]) ? 'y' : 'n';
echo $a->offsetExists(0) ? 'y' : 'n';
echo ($a[0] ?? 'D');
echo isset($a[1]) ? 'y' : 'n';
echo $a->offsetExists(1) ? 'y' : 'n';
echo ($a[1] ?? 'D');
echo "\n";
$a[1] = 0;
echo isset($a[1]) ? 'y' : 'n';
echo $a->offsetExists(1) ? 'y' : 'n';
echo "\n";
?>
--EXPECT--
nnDnnD
yy
