--TEST--
session_id Reflection ?string $id=null + string|false (#26460, session.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('session_id');
$p = $r->getParameters()[0];
echo ($p->hasType() ? (string) $p->getType() : '?'), ' $', $p->getName();
echo $p->isOptional() ? ' opt' : '';
echo ' def=', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : '?';
echo ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none';
echo "\n";
// Named id: must bind; @ avoids headers-already-sent noise in CLI compliance harness
$prev = @session_id();
$seed = ($prev === false || $prev === '') ? 'abc123sess01' : $prev;
$round = @session_id(id: $seed);
echo 'named:', (is_string($round) || $round === false) ? 'ok' : 'bad', "\n";
$get = session_id();
echo 'get:', is_string($get) ? 'str' : gettype($get), "\n";
?>
--EXPECT--
?string $id opt def=NULL ret=string|false
named:ok
get:str
