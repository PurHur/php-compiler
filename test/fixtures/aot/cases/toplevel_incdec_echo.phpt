--TEST--
AOT: script-scope inc/dec and loop accumulators echo live values (#23842)
--FILE--
<?php
$a = 1;
$a++;
$b = 2;
$b++;
echo $b, "\n";
$acc = 0;
for ($i = 0; $i < 5; ++$i) {
    ++$acc;
}
echo $acc, "\n";
?>
--EXPECT--
3
5
