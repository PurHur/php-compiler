--TEST--
SPL RecursiveFilterIterator::hasChildren/getChildren for RII descent (#20151)
--FILE--
<?php
$inner = new RecursiveArrayIterator([1, [2, 3], 4, [5, 6]]);
$it = new class($inner) extends RecursiveFilterIterator {
    public function accept(): bool
    {
        $c = $this->current();

        return is_array($c) ? true : ($c % 2 === 0);
    }
};
echo 'hasChildren=', method_exists($it, 'hasChildren') ? 'Y' : 'N', "\n";
echo 'getChildren=', method_exists($it, 'getChildren') ? 'Y' : 'N', "\n";

$top = [];
foreach ($it as $v) {
    $top[] = is_array($v) ? 'ARR' : $v;
}
echo 'top=', implode(',', $top), "\n";

$rii = new RecursiveIteratorIterator($it);
echo 'RII=', implode(',', iterator_to_array($rii, false)), "\n";

$nested = new RecursiveArrayIterator([[2, 3]]);
$filter = new class($nested) extends RecursiveFilterIterator {
    public function accept(): bool
    {
        return true;
    }
};
$filter->rewind();
$child = $filter->getChildren();
echo 'child_class=', get_class($child) === get_class($filter) ? 'same' : 'diff', "\n";
$child->rewind();
echo 'child_cur=', $child->current(), "\n";
--EXPECT--
hasChildren=Y
getChildren=Y
top=ARR,4,ARR
RII=2,4,6
child_class=same
child_cur=2
