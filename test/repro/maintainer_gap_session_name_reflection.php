<?php
// Repro #31423 — session_name Reflection ?string $name=null → string|false (session.stub.php)
$r = new ReflectionFunction('session_name');
$p = $r->getParameters()[0];
echo ($p->hasType() ? (string) $p->getType() : '?'), ' $', $p->getName();
echo $p->isOptional() ? ' opt' : '';
echo ' def=', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : '?';
echo ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none';
echo "\n";
$prev = @session_name();
$round = @session_name(name: $prev);
echo 'named:', (is_string($round) || $round === false) ? 'ok' : 'bad', "\n";
$get = session_name();
echo 'get:', is_string($get) ? 'str' : gettype($get), "\n";
