<?php
$fmt = @numfmt_create('en_US', NumberFormatter::DECIMAL);
echo intl_get_error_code(), '|', intl_get_error_message(), "\n";
var_export($fmt instanceof NumberFormatter);
echo "\n";
