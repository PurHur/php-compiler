--TEST--
SPL CachingIterator flag constants (issue #13156, ext/spl/spl_iterators.c)
--FILE--
<?php
echo CachingIterator::CALL_TOSTRING, "\n";
echo CachingIterator::TOSTRING_USE_KEY, "\n";
echo CachingIterator::TOSTRING_USE_CURRENT, "\n";
echo CachingIterator::TOSTRING_USE_INNER, "\n";
echo CachingIterator::FULL_CACHE, "\n";
?>
--EXPECT--
1
2
4
8
256
