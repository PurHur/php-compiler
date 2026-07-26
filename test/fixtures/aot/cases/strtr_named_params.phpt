--TEST--
AOT: strtr() named string:/from:/to: arguments (#23215)
--FILE--
<?php
// Array-form strtr still segfaults under AOT (existing positional gap);
// gate Zend stub named-arg acceptance on the two-string form only.
echo strtr(string: 'abc', from: 'a', to: 'x'), "\n";
echo strtr(string: 'baab', from: 'ab', to: '12'), "\n";
--EXPECT--
xbc
2112
