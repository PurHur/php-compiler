<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
$ok = @ob_flush();
$last = error_get_last();
echo ($last['message'] ?? 'none'), "\n";
var_export($ok);
echo "\n";
