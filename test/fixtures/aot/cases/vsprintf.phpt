--TEST--
AOT: vsprintf() format + args array (issue #3190)
--FILE--
<?php
echo vsprintf('%s-%d', ['a', 3]);
--EXPECT--
a-3
