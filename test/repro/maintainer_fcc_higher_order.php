<?php
declare(strict_types=1);

var_export(array_map(strtoupper(...), ['a', 'b']));
echo "\n";
var_export(array_filter(['a', '', 'c'], strlen(...)));
echo "\n";
echo call_user_func_array(strtoupper(...), ['b']), "\n";
