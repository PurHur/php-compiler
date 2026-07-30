<?php
/**
 * #24791 — levenshtein Reflection cost params optional with default 1.
 * php-src: ext/standard/string.stub.php
 */
$r = new ReflectionFunction('levenshtein');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' optional=', $p->isOptional() ? '1' : '0';
    if ($p->isDefaultValueAvailable()) {
        echo ' def=', var_export($p->getDefaultValue(), true);
    }
    echo "\n";
}
echo 'required=', $r->getNumberOfRequiredParameters(), "\n";
echo 'dist=', levenshtein('abc', 'ab'), "\n";
echo 'named=', levenshtein(string1: 'abc', string2: 'ab'), "\n";
echo 'five=', levenshtein('abc', 'ab', 1, 1, 1), "\n";
