--TEST--
AOT: trim/ltrim/rtrim() optional $characters mask (issue #3709)
--FILE--
<?php
echo ltrim('..x', '.'), "\n";
echo rtrim('x..', '.'), "\n";
echo trim('..x..', '.'), "\n";
--EXPECT--
x
x
x
