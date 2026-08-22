--TEST--
AOT: SplPriorityQueue setExtractFlags / getExtractFlags / extract format (#33861)
--FILE--
<?php
$q = new SplPriorityQueue();
$q->insert('a', 1);
$q->insert('b', 2);

$q->setExtractFlags(SplPriorityQueue::EXTR_DATA);
echo $q->getExtractFlags(), "\n";
echo $q->top(), "\n";

$q->setExtractFlags(SplPriorityQueue::EXTR_PRIORITY);
echo $q->getExtractFlags(), "\n";
echo $q->top(), "\n";

$q->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
echo $q->getExtractFlags(), "\n";
$x = $q->extract();
echo is_array($x) ? 'arr' : 'not', ':', $x['data'], '|', $x['priority'], "\n";
$y = $q->extract();
echo is_array($y) ? 'arr' : 'not', ':', $y['data'], '|', $y['priority'], "\n";
?>
--EXPECT--
1
b
2
2
3
arr:b|2
arr:a|1
