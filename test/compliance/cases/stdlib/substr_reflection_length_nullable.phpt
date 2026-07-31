--TEST--
stdlib substr Reflection length=?int=NULL (#25749, ext/standard/basic_functions.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('substr');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ':', $p->isOptional() ? 'opt' : 'req';
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo '=', var_export($p->getDefaultValue(), true);
    }
    echo ' typ=', $p->hasType() ? (string) $p->getType() : '-', "\n";
}
echo 'runtime=', substr('abcdef', 1, null), "\n";
echo 'named=', substr(string: 'abcdef', offset: 1, length: null), "\n";
?>
--EXPECT--
string:req typ=string
offset:req typ=int
length:opt=NULL typ=?int
runtime=bcdef
named=bcdef
