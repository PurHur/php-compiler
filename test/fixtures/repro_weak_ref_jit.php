<?php
class Box {}
$o = new Box();
$r = WeakReference::create($o);
$alive = $r->get();
echo $alive !== null ? '1' : '0';
unset($o);
$dead = $r->get();
echo $dead === null ? '1' : '0';
