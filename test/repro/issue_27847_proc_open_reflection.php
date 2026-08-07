<?php
/**
 * #27847 — proc_open Reflection: untyped &$pipes, nullable optionals, no return.
 */
$r = new ReflectionFunction('proc_open');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), '|', $p->hasType() ? (string) $p->getType() : 'none',
        '|byRef=', $p->isPassedByReference() ? 1 : 0,
        '|opt=', $p->isOptional() ? 1 : 0, "\n";
}
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
