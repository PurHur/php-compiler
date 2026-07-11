--TEST--
AOT: output_add_rewrite_var() registers URL-Rewriter ob handler (#12854)
--FILE--
<?php
var_export(output_add_rewrite_var('NAME', 'value'));
echo "\n";
var_export(ob_list_handlers());
echo "\n";
--EXPECT--
true
array (
  0 => 'URL-Rewriter',
)
