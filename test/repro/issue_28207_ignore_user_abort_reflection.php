<?php
$r = new ReflectionFunction('ignore_user_abort');
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : '<none>', "\n";
$p = $r->getParameters()[0];
echo 'enable type=', $p->hasType() ? (string) $p->getType() : '<none>', "\n";
echo 'optional=', $p->isOptional() ? '1' : '0', "\n";
echo 'default=', $p->isOptional() ? var_export($p->getDefaultValue(), true) : '-', "\n";
