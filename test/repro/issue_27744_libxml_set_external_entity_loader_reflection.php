<?php

$r = new ReflectionFunction('libxml_set_external_entity_loader');
$p = $r->getParameters()[0];
echo 'param=', $p->hasType() ? (string) $p->getType() : '(none)', PHP_EOL;
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', PHP_EOL;
echo 'null_ok=', (int) libxml_set_external_entity_loader(null), PHP_EOL;
