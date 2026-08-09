--TEST--
stdlib stream_socket_server Reflection untyped error outs + no return (#28857, streamsfuncs.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('stream_socket_server');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ':', $p->hasType() ? (string) $p->getType() : 'none', $p->isPassedByReference() ? '&' : '', $p->isOptional() ? '?' : '', PHP_EOL;
}
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', PHP_EOL;
// client sibling must stay Zend-aligned (#27848)
$c = new ReflectionFunction('stream_socket_client');
echo 'client_ret=', $c->hasReturnType() ? (string) $c->getReturnType() : 'none', PHP_EOL;
echo 'client_ec=', $c->getParameters()[1]->hasType() ? (string) $c->getParameters()[1]->getType() : 'none', PHP_EOL;
?>
--EXPECT--
address:string
error_code:none&?
error_message:none&?
flags:int?
context:none?
ret=none
client_ret=none
client_ec=none
