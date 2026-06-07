--TEST--
stdlib ctype_* registered; function_exists true (issue #6837)
--FILE--
<?php
foreach (['ctype_alnum', 'ctype_alpha', 'ctype_digit', 'ctype_space'] as $fn) {
    echo $fn, '=', var_export(function_exists($fn), true), "\n";
}
echo 'extension_loaded=', var_export(extension_loaded('ctype'), true), "\n";
?>
--EXPECT--
ctype_alnum=true
ctype_alpha=true
ctype_digit=true
ctype_space=true
extension_loaded=true
