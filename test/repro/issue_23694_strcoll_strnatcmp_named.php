<?php
/**
 * #23694 — strcoll/strnatcmp Zend stub names (string.stub.php).
 * InternalArgInfo still uses str1/str2 and s1/s2.
 */
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

echo 'strcoll=', strcoll(string1: 'a1', string2: 'a2'), "\n";
echo 'strnatcmp=', strnatcmp(string1: 'a1', string2: 'a2'), "\n";
echo 'strnatcasecmp=', strnatcasecmp(string1: 'A1', string2: 'a2'), "\n";

try {
    strcoll(str1: 'a1', str2: 'a2');
    echo "legacy strcoll str1 accepted\n";
} catch (Throwable $e) {
    echo 'legacy_strcoll=', $e->getMessage(), "\n";
}
try {
    strnatcmp(s1: 'a1', s2: 'a2');
    echo "legacy strnatcmp s1 accepted\n";
} catch (Throwable $e) {
    echo 'legacy_strnatcmp=', $e->getMessage(), "\n";
}
