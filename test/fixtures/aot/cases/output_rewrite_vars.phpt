--TEST--
AOT: output_add_rewrite_var() / output_reset_rewrite_vars() (#6031)
--FILE--
<?php
echo output_add_rewrite_var('NAME', 'value') ? 'true' : 'false', "\n";
echo output_reset_rewrite_vars() ? 'true' : 'false', "\n";
--EXPECT--
true
true
