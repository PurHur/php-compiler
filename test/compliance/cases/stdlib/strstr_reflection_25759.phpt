--TEST--
strstr Reflection needle string + before_needle bool optional (#25759, string.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('strstr');
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
var_export(strstr(haystack: 'abcdef', needle: 'cd'));
echo "\n";
--EXPECT--
haystack type=string opt=N
needle type=string opt=N
before_needle type=bool opt=Y def=false
ret=string|false
'cdef'
