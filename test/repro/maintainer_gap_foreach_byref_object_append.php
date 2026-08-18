<?php
// #32128 — same leak via object property
$o = new class {
    public array $items = [1, 2];
};
foreach ($o->items as &$v) {
    if ($v === 2) {
        $o->items[] = 3;
    }
}
unset($v);
var_dump($o->items);
