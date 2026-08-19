<?php
$rf = new ReflectionFunction('version_compare');
foreach ($rf->getParameters() as $p) {
    echo 'p=', $p->getName(), ' type=', $p->hasType() ? (string)$p->getType() : '(none)', ' optional=', $p->isOptional() ? 'yes' : 'no', "\n";
}
echo 'ret=', $rf->hasReturnType() ? (string)$rf->getReturnType() : '(none)', "\n";

// Functional check
echo version_compare('1.0', '1.1'), "\n";         // -1
echo version_compare('1.0', '1.1', '<'), "\n";     // 1 (true)
echo version_compare('1.0', '1.1', '>'), "\n";     // (empty, false)
