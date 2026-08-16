--TEST--
session_name Reflection ?string $name=null + string|false (#31423, session.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('session_name');
$p = $r->getParameters()[0];
echo ($p->hasType() ? (string) $p->getType() : '?'), ' $', $p->getName();
echo $p->isOptional() ? ' opt' : '';
echo ' def=', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : '?';
echo ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none';
echo "\n";
// Named name: must bind; @ avoids headers-already-sent noise in CLI compliance harness
$prev = @session_name();
$seed = ($prev === false || $prev === '') ? 'PHPSESSID' : $prev;
$round = @session_name(name: $seed);
echo 'named:', (is_string($round) || $round === false) ? 'ok' : 'bad', "\n";
$get = session_name();
echo 'get:', is_string($get) ? 'str' : gettype($get), "\n";
?>
--EXPECT--
?string $name opt def=NULL ret=string|false
named:ok
get:str
