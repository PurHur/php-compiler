<?php
require __DIR__.'/callee.php';
ob_start();
var_dump(takesInt('1'));
$output = ob_get_clean();
echo 'weak:', trim($output), "\n";
require __DIR__.'/caller_strict.php';
