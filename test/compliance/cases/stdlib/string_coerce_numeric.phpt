--TEST--
stdlib string search builtins coerce numeric operands (#3549)
--FILE--
<?php
echo str_contains(123, '2') ? 'true' : 'false', "\n";
echo strpos(123, '2'), "\n";
echo strlen(123), "\n";
echo str_starts_with(1234, '12') ? 'true' : 'false', "\n";
echo str_ends_with(1234, '34') ? 'true' : 'false', "\n";
--EXPECT--
true
1
3
true
true
