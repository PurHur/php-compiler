<?php
/**
 * Issue #25135 — fputcsv Reflection separator/enclosure/escape/eol defaults
 * (ext/standard/file.stub.php).
 */
$r = new ReflectionFunction('fputcsv');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' opt=', (int) $p->isOptional(), ' defAvail=', (int) $p->isDefaultValueAvailable();
    if ($p->isDefaultValueAvailable()) {
        echo ' ', var_export($p->getDefaultValue(), true);
    }
    if ($p->hasType()) {
        echo ' type=', $p->getType();
    }
    echo "\n";
}
$s = fopen('php://memory', 'r+');
fputcsv(stream: $s, fields: ['a', 'b']);
rewind($s);
echo 'named=', var_export(stream_get_contents($s), true), "\n";
$s2 = fopen('php://memory', 'r+');
fputcsv($s2, ['a', 'b'], eol: "\r\n");
rewind($s2);
echo 'eol=', var_export(stream_get_contents($s2), true), "\n";
