--TEST--
Generator with local state between yields (issue #167)
--FILE--
<?php
function counter($to) {
    $i = 0;
    while ($i < $to) {
        yield $i;
        $i++;
    }
}
foreach (counter(3) as $n) {
    echo $n;
}
echo "\n";
--EXPECT--
012
