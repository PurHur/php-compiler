<?php
/**
 * Issue #25749 — substr Reflection $length is ?int = null (ext/standard/basic_functions.stub.php).
 * Zend: type=?int allowsNull=1; runtime substr('abcdef', 1, null) === 'bcdef'.
 */
$r = new ReflectionFunction('substr');
foreach ($r->getParameters() as $p) {
    if ($p->getName() !== 'length') {
        continue;
    }
    $t = $p->getType();
    echo 'type=', $t ? (string) $t : 'none';
    echo ' allowsNull=', ($t && $t->allowsNull()) ? '1' : '0';
    echo ' opt=', $p->isOptional() ? '1' : '0';
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo ' def=', json_encode($p->getDefaultValue());
    }
    echo "\n";
}
echo substr('abcdef', 1, null), "\n";
