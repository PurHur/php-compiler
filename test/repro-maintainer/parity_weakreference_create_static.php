<?php
$obj = new stdClass();
$w = WeakReference::create($obj);
var_dump($w->get() !== null);
unset($obj);
var_dump($w->get());
