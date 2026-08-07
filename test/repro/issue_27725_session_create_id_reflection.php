<?php
// Repro #27725 — session_create_id Reflection string|false + optional prefix="" (session.stub.php)
$rf = new ReflectionFunction('session_create_id');
echo 'return=', $rf->hasReturnType() ? (string) $rf->getReturnType() : '(none)', "\n";
$p = $rf->getParameters()[0];
echo 'name=', $p->getName(), "\n";
echo 'optional=', $p->isOptional() ? 'yes' : 'no', "\n";
echo 'defaultAvail=', $p->isDefaultValueAvailable() ? 'yes' : 'no', "\n";
echo 'default=', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : '?', "\n";
$id = session_create_id(prefix: '');
echo 'named=', is_string($id) ? 'str' : gettype($id), "\n";
