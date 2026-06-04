--TEST--
Language: square-bracket string offset still works (#5313)
--FILE--
<?php
echo "abc"[0], "\n";
--EXPECT--
a
