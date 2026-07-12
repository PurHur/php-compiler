<?php

$all = ini_get_all(null, true);
var_export($all['assert.callback']['global_value']);
echo "\n";
var_export($all['assert.callback']['local_value']);
echo "\n";
