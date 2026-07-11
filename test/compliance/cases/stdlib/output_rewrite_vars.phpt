--TEST--
stdlib output_add_rewrite_var()/output_reset_rewrite_vars() (issue #6031, ext/standard/url.c)
--FILE--
<?php
echo function_exists('output_add_rewrite_var') ? 'add:yes' : 'add:no', "\n";
echo function_exists('output_reset_rewrite_vars') ? 'reset:yes' : 'reset:no', "\n";
var_export(output_add_rewrite_var('NAME', 'value'));
echo "\n";
var_export(output_add_rewrite_var('NAME', 'replaced'));
echo "\n";
var_export(ob_list_handlers());
echo "\n";
var_export(1 === count(ob_get_status(true)));
echo "\n";
var_export(output_reset_rewrite_vars());
echo "\n";
try {
    output_add_rewrite_var([], 'x');
    echo "array: uncaught\n";
} catch (TypeError $e) {
    echo 'array: TypeError', "\n";
}
--EXPECT--
add:yes
reset:yes
true
array (
  0 => 'URL-Rewriter',
)
true
true
array: TypeError
