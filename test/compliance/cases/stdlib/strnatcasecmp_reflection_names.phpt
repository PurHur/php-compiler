--TEST--
stdlib strnatcasecmp/strnatcmp Reflection string1/string2 (#24866, ext/standard/string.stub.php)
--FILE--
<?php
foreach (['strnatcasecmp', 'strnatcmp'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, '=', implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), "\n";
}
echo strnatcasecmp(string1: 'a', string2: 'b'), "\n";
echo strnatcmp(string1: 'a', string2: 'b'), "\n";
try {
    strnatcasecmp(s1: 'a', s2: 'b');
    echo "legacy-ok\n";
} catch (Throwable $e) {
    echo 'legacy:', $e->getMessage(), "\n";
}
?>
--EXPECT--
strnatcasecmp=string1,string2
strnatcmp=string1,string2
-1
-1
legacy:Unknown named parameter $s1
