<?php
// #20136 — children($ns) foreach keys are local names (php-src ext/simplexml/sxe.c)
$xml = simplexml_load_string('<r xmlns:n="urn:x"><n:a>1</n:a><n:b>2</n:b></r>');
$keys = [];
$names = [];
foreach ($xml->children('urn:x') as $k => $v) {
    $keys[] = $k;
    $names[] = $v->getName();
}
echo 'keys=' . json_encode($keys) . "\n";
echo 'names=' . json_encode($names) . "\n";

$prefixKeys = [];
foreach ($xml->children('n', true) as $k => $v) {
    $prefixKeys[] = $k;
}
echo 'prefix_keys=' . json_encode($prefixKeys) . "\n";
