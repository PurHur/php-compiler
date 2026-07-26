--TEST--
XMLWriter Reflection method names match php-src stub (#23367)
--FILE--
<?php
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
    echo $rm->getName(), "\n";
}
--EXPECT--
openUri
writeAttributeNs
startElementNs
startAttributeNs
writeElementNs
writeCdata
startCdata
endCdata
startPi
endPi
writePi
