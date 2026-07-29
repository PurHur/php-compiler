--TEST--
stdlib unpack Reflection offset optional default 0 (#24896, ext/standard/basic_functions.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('unpack');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' opt=', (int) $p->isOptional(),
        ' def=', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : '-', "\n";
}
echo json_encode(unpack(format: 'C*', string: 'AB', offset: 1)), "\n";
echo json_encode(unpack('C*', 'AB')), "\n";
?>
--EXPECT--
format opt=0 def=-
string opt=0 def=-
offset opt=1 def=0
{"1":66}
{"1":65,"2":66}
