--TEST--
stdlib output_add_rewrite_var()/output_reset_rewrite_vars() JIT (issue #6031)
--JIT--
--FILE--
<?php
var_export(output_add_rewrite_var('k', 'v'));
echo "\n";
var_export(output_reset_rewrite_vars());
echo "\n";
--EXPECT--
true
true
