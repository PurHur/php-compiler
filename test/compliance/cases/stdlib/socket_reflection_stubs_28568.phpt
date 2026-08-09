--TEST--
stdlib socket_* Reflection Socket params + |false / ?int (#28568, sockets.stub.php)
--FILE--
<?php
foreach (['socket_connect', 'socket_bind', 'socket_listen', 'socket_read', 'socket_write'] as $f) {
    $r = new ReflectionFunction($f);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $ps[] = $p->getName() . ':' . ($p->hasType() ? (string) $p->getType() : '?');
    }
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'untyped', ' [', implode(', ', $ps), ']', PHP_EOL;
}
?>
--EXPECT--
socket_connect ret=bool [socket:Socket, address:string, port:?int]
socket_bind ret=bool [socket:Socket, address:string, port:int]
socket_listen ret=bool [socket:Socket, backlog:int]
socket_read ret=string|false [socket:Socket, length:int, mode:int]
socket_write ret=int|false [socket:Socket, data:string, length:?int]
