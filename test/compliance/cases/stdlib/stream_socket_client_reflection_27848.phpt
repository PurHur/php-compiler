--TEST--
stdlib stream_socket_client Reflection untyped error outs + no return (#27848, streamsfuncs.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('stream_socket_client');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ':', $p->hasType() ? (string) $p->getType() : 'none', $p->isPassedByReference() ? '&' : '', $p->isOptional() ? '?' : '', PHP_EOL;
}
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', PHP_EOL;
?>
--EXPECT--
address:string
error_code:none&?
error_message:none&?
timeout:?float?
flags:int?
context:none?
ret=none
