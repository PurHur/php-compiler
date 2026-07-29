--TEST--
stdlib range Reflection step default 1 (#25070, ext/standard/array.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('range');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' opt=', (int) $p->isOptional(),
        ' def=', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : '-', "\n";
}
echo json_encode(range(1, 3)), "\n";
echo json_encode(range(start: 1, end: 5, step: 2)), "\n";
?>
--EXPECT--
start opt=0 def=-
end opt=0 def=-
step opt=1 def=1
[1,2,3]
[1,3,5]
