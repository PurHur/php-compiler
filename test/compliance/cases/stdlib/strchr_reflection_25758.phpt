--TEST--
strchr Reflection before_needle bool optional + string|false (#25758, string.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('strchr');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' type=', $p->getType() ? (string) $p->getType() : 'none',
        ' opt=', $p->isOptional() ? 'Y' : 'N';
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo ' def=', var_export($p->getDefaultValue(), true);
    }
    echo "\n";
}
$rt = $r->getReturnType();
echo 'ret=', $rt ? (string) $rt : 'none', "\n";
var_export(strchr(haystack: 'abcdef', needle: 'd', before_needle: true));
echo "\n";
--EXPECT--
haystack type=string opt=N
needle type=string opt=N
before_needle type=bool opt=Y def=false
ret=string|false
'abc'
