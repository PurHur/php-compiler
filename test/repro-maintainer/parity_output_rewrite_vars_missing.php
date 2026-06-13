<?php

foreach (['output_add_rewrite_var', 'output_reset_rewrite_vars'] as $fn) {
    echo $fn, ': ', function_exists($fn) ? 'REGISTERED' : 'MISSING', "\n";
}
if (function_exists('output_add_rewrite_var')) {
    var_export(output_add_rewrite_var('NAME', 'value'));
    echo "\n";
    var_export(output_add_rewrite_var('NAME', 'replaced'));
    echo "\n";
    var_export(output_reset_rewrite_vars());
    echo "\n";
}
