--TEST--
AOT: str_replace/str_ireplace/substr_replace(null) E_DEPRECATED (#19755)
--FILE--
<?php
error_reporting(E_ALL);
$n = null;
echo var_export(str_replace('a', 'b', $n), true), "\n";
echo var_export(str_ireplace('a', 'b', $n), true), "\n";
echo var_export(substr_replace($n, 'x', 0), true), "\n";
?>
--EXPECTF--
PHP Deprecated:  str_replace(): Passing null to parameter #3 ($subject) of type array|string is deprecated in %s on line %d
PHP Deprecated:  str_ireplace(): Passing null to parameter #3 ($subject) of type array|string is deprecated in %s on line %d
PHP Deprecated:  substr_replace(): Passing null to parameter #1 ($string) of type array|string is deprecated in %s on line %d
''
''
'x'
