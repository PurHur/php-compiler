--TEST--
language WeakReference::get() null after unset (#5089)
--FILE--
<?php
$obj = new stdClass();
$w = WeakReference::create($obj);
echo $w->get() !== null ? '1' : '0';
unset($obj);
echo $w->get() === null ? '1' : '0';
--EXPECT--
11
