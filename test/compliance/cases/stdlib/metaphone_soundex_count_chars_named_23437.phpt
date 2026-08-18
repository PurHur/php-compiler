--TEST--
metaphone/soundex/count_chars Zend stub names + named args (#23437, string.stub.php)
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
        if ($p->isOptional() && $p->isDefaultValueAvailable()) {
            echo ' def=', var_export($p->getDefaultValue(), true);
        }
    }
    echo "\n";
}

dumpParams('metaphone');
dumpParams('soundex');
dumpParams('count_chars');

echo metaphone(string: 'programming', max_phonemes: 4), "\n";
echo soundex(string: 'Euler'), "\n";
echo count_chars(string: 'a', mode: 3), "\n";

try {
    metaphone(text: 'programming');
    echo "legacy metaphone text accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
try {
    soundex(str: 'Euler');
    echo "legacy soundex str accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
try {
    count_chars(input: 'a');
    echo "legacy count_chars input accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
metaphone ret=string | string type=string opt=N | max_phonemes type=int opt=Y def=0
soundex ret=string | string type=string opt=N
count_chars ret=array|string | string type=string opt=N | mode type=int opt=Y def=0
PRKR
E460
a
Unknown named parameter $text
Unknown named parameter $str
Unknown named parameter $input
