<?php
/**
 * Issue #24896 — unpack Reflection $offset optional default 0
 * (ext/standard/basic_functions.stub.php).
 */
$r = new ReflectionFunction('unpack');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' opt=', (int) $p->isOptional(),
        ' def=', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : '-', "\n";
}
echo json_encode(unpack(format: 'C*', string: 'AB', offset: 1)), "\n";
echo json_encode(unpack('C*', 'AB')), "\n";
