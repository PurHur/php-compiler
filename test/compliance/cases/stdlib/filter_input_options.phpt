--TEST--
stdlib filter_input() four-arg form binds filter and options (ext/filter/filter.c, #15194)
--FILE--
<?php
$_GET = [];
var_export(filter_input(INPUT_GET, 'missing', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]));
echo "\n";
?>
--EXPECT--
NULL
