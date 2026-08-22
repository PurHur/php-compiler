<?php

declare(strict_types=1);

// #27982 — debug_zval_dump Reflection return void + mixed params (re-#23679).
$r = new ReflectionFunction('debug_zval_dump');
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ':', $p->hasType() ? (string) $p->getType() : '?', $p->isOptional() ? ' opt' : '', "\n";
}
debug_zval_dump(value: 1);
echo "named_ok\n";
