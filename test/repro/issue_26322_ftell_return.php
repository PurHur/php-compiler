<?php
// #26322 — ftell Reflection return int|false (file.stub.php).
$r = new ReflectionFunction('ftell');
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
