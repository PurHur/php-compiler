<?php
declare(strict_types=1);

var_export(array_column([['name' => 'a'], ['name' => 'b']], 'name', null));
echo "\n";

var_export(array_search(null, [1, 2, 3], true));
echo "\n";

var_export(in_array(null, [1, 2, 3], true));
echo "\n";
