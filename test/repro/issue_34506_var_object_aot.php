<?php
echo var_export(new stdClass, true), "\n";
echo var_export((object)['a' => 1], true), "\n";
echo var_export((object)['a' => 1, 'b' => [2]], true), "\n";
echo print_r((object)['a' => 1], true);
var_dump((object)['a' => 1]);
var_dump(new stdClass);
