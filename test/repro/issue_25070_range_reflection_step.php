<?php
/**
 * Issue #25070 — range Reflection $step default 1 (ext/standard/array.stub.php).
 */
$r = new ReflectionFunction('range');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' opt=', (int) $p->isOptional(),
        ' def=', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : '-', "\n";
}
echo json_encode(range(1, 3)), "\n";
echo json_encode(range(start: 1, end: 5, step: 2)), "\n";
