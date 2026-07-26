--TEST--
wordwrap named cut_long_words argument (VM, issue #23191)
--FILE--
<?php
var_export(wordwrap(string: 'a b c', width: 2, break: "\n", cut_long_words: true));
echo PHP_EOL;
$rf = new ReflectionFunction('wordwrap');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), PHP_EOL;
}
try {
    wordwrap(string: 'a', cut: true);
    echo "cut accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
try {
    wordwrap('abc', 0, "\n", true);
    echo "no value error\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
'a
b
c'
string
width
break
cut_long_words
Unknown named parameter $cut
wordwrap(): Argument #4 ($cut_long_words) cannot be true when argument #2 ($width) is 0
