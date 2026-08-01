<?php
// #26323 — symlink Reflection return bool (link.stub.php).
$r = new ReflectionFunction('symlink');
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
