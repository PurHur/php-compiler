--TEST--
stdlib dirname() optional $levels argument
--FILE--
<?php
echo dirname('/a/b/c/d', 2), "\n";
echo dirname('/a/b/c/d', 1), "\n";
echo dirname('/a/b/c/d', '2'), "\n";
echo dirname('/a/b', 5), "\n";
--EXPECT--
/a/b
/a/b/c
/a/b
/
