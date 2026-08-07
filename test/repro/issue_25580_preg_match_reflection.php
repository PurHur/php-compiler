<?php
// Issue #25580 — preg_match Reflection stub vs Zend (untyped &$matches, int|false).
foreach (['preg_match', 'preg_match_all'] as $f) {
    $r = new ReflectionFunction($f);
    $bits = [];
    foreach ($r->getParameters() as $p) {
        $bits[] = $p->getName()
            . ':'
            . ($p->getType() ?: 'none')
            . ($p->isPassedByReference() ? '&' : '');
    }
    echo $f, ' ', implode('|', $bits), ' ret=', (string) $r->getReturnType(), "\n";
}
