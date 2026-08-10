--TEST--
AOT mb_substitute_character(null) returns current subst under strict_types (#29919)
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
echo var_export(mb_substitute_character(null), true), "\n";
?>
--EXPECT--
63
