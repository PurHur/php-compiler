--TEST--
SplMaxHeap / SplMinHeap extract order (#4387)
--FILE--
<?php
$max = new SplMaxHeap();
$max->insert(1);
$max->insert(3);
$max->insert(2);
echo $max->extract(), ',', $max->extract(), ',', $max->top(), "\n";

$min = new SplMinHeap();
$min->insert(3);
$min->insert(1);
$min->insert(2);
echo $min->extract(), ',', $min->extract(), ',', $min->extract(), "\n";

try {
    new SplHeap();
} catch (Error $e) {
    echo "abstract\n";
}
?>
--EXPECT--
3,2,1
1,2,3
abstract
