<?php
// AOT: filter_input_array CLI NULL — must not abort (#34580)
var_export(filter_input_array(INPUT_GET, FILTER_DEFAULT));
echo "\n";
var_export(filter_input_array(INPUT_GET, ['x' => FILTER_VALIDATE_INT]));
echo "\n";
