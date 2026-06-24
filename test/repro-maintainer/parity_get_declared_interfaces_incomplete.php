<?php
// Maintainer repro (#11247): get_declared_interfaces() must list Zend builtin interfaces.
$required = ['DOMChildNode', 'DOMParentNode', 'Reflector', 'SeekableIterator'];
$ifaces = get_declared_interfaces();
$missing = [];
foreach ($required as $name) {
    if (!in_array($name, $ifaces, true)) {
        $missing[] = $name;
    }
    if (!interface_exists($name)) {
        $missing[] = $name.' (interface_exists)';
    }
}
if ([] !== $missing) {
    echo 'FAIL missing: ', implode(', ', array_unique($missing)), "\n";
    exit(1);
}
echo 'ok count=', count($ifaces), "\n";
