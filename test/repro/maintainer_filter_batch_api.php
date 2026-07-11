<?php
declare(strict_types=1);

// VM path: runtime $_GET assignment (issue #3294 repro).
$_GET = ['id' => '42', 'bad' => 'x'];
var_export(filter_has_var(INPUT_GET, 'id'));
echo "\n";
var_export(filter_has_var(INPUT_GET, 'missing'));
echo "\n";
var_export(filter_input_array(INPUT_GET, ['id' => FILTER_VALIDATE_INT]));
echo "\n";
var_export(filter_var_array(['email' => 'a@b.c'], ['email' => FILTER_VALIDATE_EMAIL]));
echo "\n";
