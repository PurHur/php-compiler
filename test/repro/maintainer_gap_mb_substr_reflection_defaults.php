<?php
/**
 * Issue #25362 — mb_substr Reflection length/encoding defaults (ext/mbstring/mbstring.stub.php).
 * Zend: length=?int=NULL, encoding=?string=NULL.
 */
$r = new ReflectionFunction('mb_substr');
foreach ($r->getParameters() as $p) {
    echo $p->getName();
    if ($p->hasType()) {
        echo ':' . $p->getType();
    }
    echo $p->isOptional() ? ' OPT' : ' REQ';
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo '=' . json_encode($p->getDefaultValue());
    }
    echo "\n";
}
echo 'runtime=', mb_substr('abcdef', 2), "\n";
