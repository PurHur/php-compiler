<?php
trigger_error('hi', E_USER_WARNING);
$e = error_get_last();
echo isset($e['message']) ? $e['message'] : 'none';
echo "\n";
