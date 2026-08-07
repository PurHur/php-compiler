--TEST--
AOT Transliterator::transliterate Any-Latin; Latin-ASCII (#28657)
--FILE--
<?php
$t = Transliterator::create('Any-Latin; Latin-ASCII');
echo $t->transliterate('café'), "\n";
--EXPECT--
cafe
