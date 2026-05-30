<?php
eval('syntax error;');
$fn = 'error_get_last';
$err = $fn();
echo is_array($err) ? "has-error\n" : "no-error\n";
echo is_array($err) && $err['type'] === 4 ? "4\n" : "type\n";
echo is_array($err) && str_contains($err['file'], 'eval') ? "eval-file\n" : "file\n";
