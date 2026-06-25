--TEST--
array_all()/array_any() inline empty [] haystack vacuous truth (issue #11729)
--FILE--
<?php
echo array_all([], fn ($v) => (bool) $v) ? 'all' : 'notall', "\n";
echo array_any([], fn ($v) => (bool) $v) ? 'any' : 'notany', "\n";
?>
--EXPECT--
all
notany
