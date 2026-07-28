<?php
// Repro #23335 — strcmp family Zend stub named params (ext/standard/string.stub.php)
echo strcmp(string1: 'a', string2: 'b'), "\n";
echo strcasecmp(string1: 'A', string2: 'a'), "\n";
echo strncmp(string1: 'abc', string2: 'abd', length: 2), "\n";
echo strncasecmp(string1: 'AbC', string2: 'abd', length: 2), "\n";
foreach (['strcmp', 'strcasecmp', 'strncmp', 'strncasecmp'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $fn, ':', implode(',', $names), "\n";
}
try {
    strcmp(str1: 'a', str2: 'b');
    echo "legacy-ok\n";
} catch (Throwable $e) {
    echo 'legacy:', $e->getMessage(), "\n";
}
