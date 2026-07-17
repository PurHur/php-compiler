--TEST--
RegexIterator::$replacement is a declared ?string property (#20153, ext/spl/spl_iterators.c)
--FILE--
<?php
$rc = new ReflectionClass('RegexIterator');
echo 'hasProperty=', $rc->hasProperty('replacement') ? 'Y' : 'N', "\n";
$it = new RegexIterator(new ArrayIterator(['a1', 'bb', 'c3']), '/(\d)/', RegexIterator::REPLACE);
echo 'property_exists=', property_exists($it, 'replacement') ? 'Y' : 'N', "\n";
echo 'default=', var_export($it->replacement, true), "\n";
$it->replacement = 'X';
echo 'result=', implode(',', iterator_to_array($it)), "\n";
--EXPECT--
hasProperty=Y
property_exists=Y
default=NULL
result=aX,cX
