<?php
declare(strict_types=1);

echo json_encode(file_exists(__DIR__)), "\n";
$p = __DIR__;
echo json_encode(file_exists($p)), "\n";
