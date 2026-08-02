--TEST--
AOT: DateTime(Immutable)::createFromTimestamp int+float (#26936)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo DateTime::createFromTimestamp(1700000000)->format('U'), "\n";
echo DateTimeImmutable::createFromTimestamp(1700000000.5)->format('U.u'), "\n";
?>
--EXPECT--
1700000000
1700000000.500000
