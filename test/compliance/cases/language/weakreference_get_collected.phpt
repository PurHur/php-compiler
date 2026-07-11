--TEST--
language WeakReference::get() after referent collected via assign null (#13474)
--FILE--
<?php
$o = new stdClass();
$wr = WeakReference::create($o);
$o = null;
echo $wr->get() === null ? "ok\n" : "fail\n";
--EXPECT--
ok
