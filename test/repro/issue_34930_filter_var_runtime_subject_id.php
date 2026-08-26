<?php
/** AOT: runtime subject + runtime filter id must compile and match Zend (#34930). */
$fEmail = FILTER_VALIDATE_EMAIL;
$email = 'a@b.co';
var_export(filter_var($email, $fEmail));
echo "\n";

$fInt = FILTER_VALIDATE_INT;
$num = '42';
var_export(filter_var($num, $fInt));
echo "\n";

$fSan = FILTER_SANITIZE_EMAIL;
$dirty = 'a@b!.co';
echo filter_var($dirty, $fSan), "\n";
