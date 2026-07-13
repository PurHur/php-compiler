--TEST--
Language: foreach by-ref on object property — direct property call arg (#18427)
--FILE--
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
echo 'joined=', $joined, ' match=', ($joined === '10,20' ? 'true' : 'false'), "\n";
?>
--EXPECT--
joined=10,20 match=true
