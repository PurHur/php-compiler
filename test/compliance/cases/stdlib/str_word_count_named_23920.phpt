--TEST--
str_word_count named string/format/characters + Reflection (VM, issue #23920)
--FILE--
<?php
$rf = new ReflectionFunction('str_word_count');
$parts = [];
foreach ($rf->getParameters() as $p) {
    $parts[] = $p->getName().($p->isOptional() ? '=' : '');
}
echo implode(',', $parts), PHP_EOL;
echo str_word_count(string: 'a-b', format: 0, characters: '-'), PHP_EOL;
echo str_word_count('a-b', 0, '-'), PHP_EOL;
try {
    str_word_count(string: 'a-b', format: 0, charlist: '-');
    echo "legacy accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
string,format=,characters=
1
1
Unknown named parameter $charlist
