--TEST--
strcoll/strnatcmp Zend stub names + named args (#23694, string.stub.php)
--FILE--
<?php
function dumpParams(string $fn): void
{
    $r = new ReflectionFunction($fn);
    $rt = $r->getReturnType();
    echo $fn, ' ret=', $rt ? (string) $rt : 'none';
    foreach ($r->getParameters() as $p) {
        echo ' | ', $p->getName(),
            ' type=', $p->getType() ? (string) $p->getType() : 'none',
            ' opt=', $p->isOptional() ? 'Y' : 'N';
    }
    echo "\n";
}

dumpParams('strcoll');
dumpParams('strnatcmp');
dumpParams('strnatcasecmp');

echo strcoll(string1: 'a1', string2: 'a2'), "\n";
echo strnatcmp(string1: 'a1', string2: 'a2'), "\n";
echo strnatcasecmp(string1: 'A1', string2: 'a2'), "\n";

try {
    strcoll(str1: 'a1', str2: 'a2');
    echo "legacy strcoll str1 accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
try {
    strnatcmp(s1: 'a1', s2: 'a2');
    echo "legacy strnatcmp s1 accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
strcoll ret=int | string1 type=string opt=N | string2 type=string opt=N
strnatcmp ret=int | string1 type=string opt=N | string2 type=string opt=N
strnatcasecmp ret=int | string1 type=string opt=N | string2 type=string opt=N
-1
-1
-1
Unknown named parameter $str1
Unknown named parameter $s1
