--TEST--
stdlib filter_input_array empty INPUT_GET → null; bool definition → false (#23369, ext/filter/filter.c)
--FILE--
<?php
echo 'arr=';
var_export(filter_input_array(INPUT_GET, ['x' => FILTER_VALIDATE_INT]));
echo "\n";
set_error_handler(static function (int $n, string $m): bool {
    echo 'warn=', $m, "\n";

    return true;
});
echo 'bool=';
var_export(filter_input_array(INPUT_GET, false));
echo "\n";
echo 'input=';
var_export(filter_input(INPUT_GET, 'x', FILTER_VALIDATE_INT));
echo "\n";
$_GET = [];
echo 'after_empty_get=';
var_export(filter_input_array(INPUT_GET, ['x' => FILTER_VALIDATE_INT]));
echo "\n";
?>
--EXPECT--
arr=NULL
bool=warn=filter_input_array(): Unknown filter with ID 0
false
input=NULL
after_empty_get=NULL
