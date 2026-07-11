<?php

declare(strict_types=1);

var_export(output_add_rewrite_var('NAME', 'value'));
echo "\n";
var_export(ob_list_handlers());
echo "\n";
var_export(count(ob_get_status(true)));
echo "\n";
var_export(output_reset_rewrite_vars());
echo "\n";
var_export(ob_list_handlers());
echo "\n";
