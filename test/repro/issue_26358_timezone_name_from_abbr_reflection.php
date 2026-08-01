<?php
// #26358 — timezone_name_from_abbr Reflection string|false + utcOffset/isDST=-1.
$r = new ReflectionFunction('timezone_name_from_abbr');
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' def=';
    echo $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : '-';
    echo "\n";
}
