--TEST--
stdlib tokenizer registered; function_exists + PhpToken (issue #6940)
--FILE--
<?php
echo 'token_get_all=', var_export(function_exists('token_get_all'), true), "\n";
echo 'token_name=', var_export(function_exists('token_name'), true), "\n";
echo 'PhpToken=', var_export(class_exists('PhpToken'), true), "\n";
echo 'T_ECHO=', var_export(defined('T_ECHO'), true), "\n";
echo 'extension_loaded=', var_export(extension_loaded('tokenizer'), true), "\n";
?>
--EXPECT--
token_get_all=true
token_name=true
PhpToken=true
T_ECHO=true
extension_loaded=true
