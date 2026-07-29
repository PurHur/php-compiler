--TEST--
stdlib fgetcsv Reflection length/separator/enclosure/escape defaults (#24826)
--FILE--
<?php
$r = new ReflectionFunction('fgetcsv');
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
$h = fopen('php://memory', 'r+');
fwrite($h, "a,b\nc,d\n");
rewind($h);
echo 'row=', var_export(fgetcsv($h), true), "\n";
?>
--EXPECT--
stream opt=0 defAvail=0
length opt=1 defAvail=1 NULL type=?int
separator opt=1 defAvail=1 ',' type=string
enclosure opt=1 defAvail=1 '"' type=string
escape opt=1 defAvail=1 '\\' type=string
row=array (
  0 => 'a',
  1 => 'b',
)
