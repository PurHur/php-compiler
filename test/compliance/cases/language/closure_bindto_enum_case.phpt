--TEST--
language: Closure::bindTo() accepts enum case as $newThis (#7201, zend_closures.c)
--FILE--
<?php
enum E: string { case A = 'x'; }
$c = function () { return $this; };
$r = $c->bindTo(E::A);
var_export($r instanceof Closure);
echo "\n";
var_export($r() === E::A);
echo "\n";
--EXPECT--
true
true
