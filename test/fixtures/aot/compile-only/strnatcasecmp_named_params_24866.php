<?php
// AOT lint-only: strnatcasecmp/strnatcmp Zend stub named params (#24866, ext/standard/string.stub.php)
// Named-arg dispatch only — ReflectionFunction::getParameters is a separate AOT case-fold gap.
$named = strnatcasecmp(string1: 'a', string2: 'b');
$pos = strnatcasecmp('a', 'b');
echo (int) ($named === $pos), "\n";
$named2 = strnatcmp(string1: 'img2', string2: 'img10');
$pos2 = strnatcmp('img2', 'img10');
echo (int) ($named2 === $pos2), "\n";
