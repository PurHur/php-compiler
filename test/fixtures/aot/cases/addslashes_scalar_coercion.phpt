--TEST--
AOT addslashes()/stripslashes() scalar coercion (#4553)
--FILE--
<?php
echo addslashes(123), "\n";
echo stripslashes(456), "\n";
--EXPECT--
123
456
