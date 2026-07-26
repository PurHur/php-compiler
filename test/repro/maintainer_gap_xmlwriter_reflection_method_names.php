<?php
/**
 * #23367 — XMLWriter ReflectionMethod::getName() must match php-src stub casing
 * (ext/xmlwriter/php_xmlwriter.stub.php): Cdata/Pi/Uri/Ns — not CData/PI/URI/NS.
 */
$want = [
    'openUri',
    'writeAttributeNs',
    'startElementNs',
    'startAttributeNs',
    'writeElementNs',
    'writeCdata',
    'startCdata',
    'endCdata',
    'startPi',
    'endPi',
    'writePi',
];
foreach ($want as $name) {
    $rm = new ReflectionMethod('XMLWriter', $name);
    echo $name, '=', $rm->getName(), ($rm->getName() === $name ? " OK\n" : " BAD\n");
}
$methods = get_class_methods('XMLWriter');
sort($methods);
foreach ($want as $name) {
    echo 'listed_', $name, '=', in_array($name, $methods, true) ? "yes\n" : "no\n";
}
