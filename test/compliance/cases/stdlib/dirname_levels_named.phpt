--TEST--
stdlib dirname() levels: named parameter (#16541)
--FILE--
<?php
echo dirname('/a/b/c/d', levels: 2), "\n";
echo dirname('/a/b/c/d', 2), "\n";
?>
--EXPECT--
/a/b
/a/b
