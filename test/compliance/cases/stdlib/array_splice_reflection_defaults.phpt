--TEST--
stdlib array_splice Reflection length=NULL/?int replacement=mixed (#24824, ext/standard/array.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('array_splice');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ':', $p->isOptional() ? 'opt' : 'req';
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo '=', var_export($p->getDefaultValue(), true);
    }
    echo ' typ=', $p->hasType() ? (string) $p->getType() : '-', "\n";
}
$a = [1, 2, 3, 4];
$removed = array_splice(array: $a, offset: 1, replacement: ['x']);
echo 'named=', json_encode($removed), '/', json_encode($a), "\n";
?>
--EXPECT--
array:req typ=array
offset:req typ=int
length:opt=NULL typ=?int
replacement:opt=array (
) typ=mixed
named=[2,3,4]/[1,"x"]
