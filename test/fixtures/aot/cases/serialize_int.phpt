--TEST--
AOT: serialize() integer (issue #1174)
--FILE--
<?php
echo serialize(42);
--EXPECT--
i:42;
