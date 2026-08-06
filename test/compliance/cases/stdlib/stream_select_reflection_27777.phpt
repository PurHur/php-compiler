--TEST--
stdlib stream_select Reflection ?array read + int|false (#27777, streamsfuncs.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('stream_select');
foreach ($r->getParameters() as $p) {
    echo ($p->isPassedByReference() ? '&' : ''), $p->getName(), ' type=', $p->hasType() ? (string) $p->getType() : 'NONE', PHP_EOL;
}
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', PHP_EOL;
?>
--EXPECT--
&read type=?array
&write type=?array
&except type=?array
seconds type=?int
microseconds type=?int
return=int|false
