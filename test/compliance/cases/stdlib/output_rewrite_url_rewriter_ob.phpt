--TEST--
stdlib output_add_rewrite_var() registers URL-Rewriter ob handler (issue #12854, ext/standard/url.c)
--FILE--
<?php
var_export(output_add_rewrite_var('NAME', 'value'));
echo "\n";
var_export(ob_list_handlers());
echo "\n";
var_export(count(ob_get_status(true)));
echo "\n";
--EXPECT--
true
array (
  0 => 'URL-Rewriter',
)
1
