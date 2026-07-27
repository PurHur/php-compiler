--TEST--
AOT ++/-- loop counters with live fopen must not false-positive (#23841)
--FILE--
<?php
$fh = fopen('php://memory', 'r+');
$acc = 0;
for ($i = 0; $i < 5; ++$i) {
    ++$acc;
}
echo $acc, "\n";
fclose($fh);
?>
--EXPECT--
5
