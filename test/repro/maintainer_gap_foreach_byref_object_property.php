<?php

class Props {
    public array $items = [1, 2];
}

$p = new Props();
foreach ($p->items as &$v) {
    $v *= 10;
}
unset($v);

$joined = implode(',', $p->items);
$expected = '10,20';
$match = ($joined === $expected);
echo 'joined=' . $joined . "\n";
echo 'match=' . ($match ? 'true' : 'false') . "\n";
