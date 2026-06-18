--TEST--
AOT: is_callable() third &$callable_name argument (issue #9505)
--FILE--
<?php
$name = null;
$ok = is_callable('strlen', false, $name);
echo ($ok ? 'true' : 'false'), ' ', $name, "\n";
?>
--EXPECT--
true strlen
