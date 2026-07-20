<?php
echo 'fn=', (int) function_exists('tidy_get_opt_doc'), "\n";
echo 'm=', (int) method_exists('tidy', 'getOptDoc'), "\n";
$t = @tidy_parse_string('<p>x</p>');
if (false === $t) {
    echo "host=0\n";
    exit(0);
}
echo "host=1\n";
$doc = @tidy_get_opt_doc($t, 'indent');
echo 'doc=', is_string($doc) ? 'str' : var_export($doc, true), "\n";
