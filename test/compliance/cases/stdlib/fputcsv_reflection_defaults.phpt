--TEST--
stdlib fputcsv Reflection separator/enclosure/escape/eol defaults (#25135)
--FILE--
<?php
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
?>
--EXPECT--
stream opt=0 defAvail=0
fields opt=0 defAvail=0 type=array
separator opt=1 defAvail=1 ',' type=string
enclosure opt=1 defAvail=1 '"' type=string
escape opt=1 defAvail=1 '\\' type=string
eol opt=1 defAvail=1 '
' type=string
named='a,b
'
eol='a,b
'
