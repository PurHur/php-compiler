<?php
declare(strict_types=1);

class Box {
    public array $items = [1, 2];
}

$p = new Box();
foreach ($p->items as &$x) {
    $x *= 10;
}
unset($x);

$joined = implode(',', $p->items);
echo 'joined=' . $joined . "\n";

$copy = $p->items;
$joinedCopy = implode(',', $copy);
echo 'joined_copy=' . $joinedCopy . "\n";

$match = ($joined === '10,20' && $joinedCopy === '10,20');
echo 'match=' . ($match ? 'true' : 'false') . "\n";
