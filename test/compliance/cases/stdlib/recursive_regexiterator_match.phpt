--TEST--
RecursiveRegexIterator MATCH mode filters recursive iterator (php-src ext/spl/spl_iterators.c, #6693)
--FILE--
<?php
$it = new RecursiveArrayIterator(['a1', 'b2', 'a3']);
$rx = new RecursiveRegexIterator($it, '/^a/', RecursiveRegexIterator::MATCH);
$seen = [];
foreach ($rx as $value) {
    $seen[] = $value;
}
echo implode(',', $seen), "\n";
--EXPECT--
a1,a3
