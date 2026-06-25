--TEST--
AOT: array_all()/array_any() inline [] haystack (issue #11729)
--FILE--
<?php
echo array_all([], fn ($v) => (bool) $v) ? 'all' : 'notall', "\n";
echo array_any([], fn ($v) => (bool) $v) ? 'any' : 'notany', "\n";
?>
--EXPECT--
all
notany
