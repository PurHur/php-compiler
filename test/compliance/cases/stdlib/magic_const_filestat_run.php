<?php
declare(strict_types=1);

echo json_encode(file_exists(__DIR__)), "\n";
echo json_encode(file_exists(__FILE__)), "\n";
echo json_encode(is_dir(__DIR__)), "\n";
echo json_encode(is_file(__FILE__)), "\n";
echo json_encode(is_link(__FILE__)), "\n";
echo json_encode(is_readable(__FILE__)), "\n";
echo json_encode(is_writable(__FILE__)), "\n";
echo json_encode(realpath(__DIR__) !== false), "\n";
echo json_encode(filetype(__DIR__)), "\n";
$p = __DIR__;
echo json_encode(file_exists($p)), "\n";
