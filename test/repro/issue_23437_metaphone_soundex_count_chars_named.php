<?php
/**
 * #23437 — metaphone/soundex/count_chars Zend stub names (string.stub.php).
 * InternalArgInfo still uses text/phones, str, input.
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
        if ($p->isOptional() && $p->isDefaultValueAvailable()) {
            echo ' def=', var_export($p->getDefaultValue(), true);
        }
    }
    echo "\n";
}

dumpParams('metaphone');
dumpParams('soundex');
dumpParams('count_chars');

echo 'metaphone=', metaphone(string: 'programming', max_phonemes: 4), "\n";
echo 'soundex=', soundex(string: 'Euler'), "\n";
echo 'count_chars=', count_chars(string: 'a', mode: 3), "\n";

try {
    metaphone(text: 'programming');
    echo "legacy metaphone text accepted\n";
} catch (Throwable $e) {
    echo 'legacy_metaphone=', $e->getMessage(), "\n";
}
try {
    soundex(str: 'Euler');
    echo "legacy soundex str accepted\n";
} catch (Throwable $e) {
    echo 'legacy_soundex=', $e->getMessage(), "\n";
}
try {
    count_chars(input: 'a');
    echo "legacy count_chars input accepted\n";
} catch (Throwable $e) {
    echo 'legacy_count_chars=', $e->getMessage(), "\n";
}
