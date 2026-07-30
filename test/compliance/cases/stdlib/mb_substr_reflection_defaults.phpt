--TEST--
stdlib mb_substr Reflection length=NULL/?int encoding=NULL/?string (#25362, ext/mbstring/mbstring.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('mb_substr');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ':', $p->isOptional() ? 'opt' : 'req';
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo '=', var_export($p->getDefaultValue(), true);
    }
    echo ' typ=', $p->hasType() ? (string) $p->getType() : '-', "\n";
}
echo 'runtime=', mb_substr('abcdef', 2), "\n";
echo 'named=', mb_substr(string: 'abcdef', start: 2, encoding: 'UTF-8'), "\n";
?>
--EXPECT--
string:req typ=string
start:req typ=int
length:opt=NULL typ=?int
encoding:opt=NULL typ=?string
runtime=cdef
named=cdef
