<?php
set_error_handler(static fn () => true);
$a = 5;
$a &= '2x';
var_export($a);
echo ' ', gettype($a), "\n";
