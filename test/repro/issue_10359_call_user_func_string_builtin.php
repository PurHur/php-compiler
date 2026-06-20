<?php

declare(strict_types=1);

var_export(is_callable('strlen'));
echo "\n";
var_export(call_user_func('strlen', 'abc'));
echo "\n";
var_export(call_user_func_array('strlen', ['abc']));
echo "\n";
