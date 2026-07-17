--TEST--
SimpleXML: children($ns) foreach keys are local names (#20136, ext/simplexml/sxe.c)
--FILE--
<?php
$xml = simplexml_load_string('<r xmlns:n="urn:x"><n:a>1</n:a><n:b>2</n:b></r>');
$keys = [];
$names = [];
foreach ($xml->children('urn:x') as $k => $v) {
    $keys[] = $k;
    $names[] = $v->getName();
}
echo json_encode($keys), "\n";
echo json_encode($names), "\n";

$prefixKeys = [];
foreach ($xml->children('n', true) as $k => $v) {
    $prefixKeys[] = $k;
}
echo json_encode($prefixKeys), "\n";

// Unqualified children unchanged
$plain = simplexml_load_string('<r><a/><b/></r>');
$plainKeys = [];
foreach ($plain as $k => $v) {
    $plainKeys[] = $k;
}
echo json_encode($plainKeys), "\n";
--EXPECT--
["a","b"]
["a","b"]
["a","b"]
["a","b"]
