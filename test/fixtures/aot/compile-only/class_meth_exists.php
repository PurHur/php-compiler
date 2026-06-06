<?php
// Compile-only (#7068): class_meth_exists() lowers for native AOT.
declare(strict_types=1);
class Box {
    public function __construct() {}
}
echo (function_exists('class_meth_exists') ? '1' : '0');
echo (class_meth_exists('Box', '__construct') ? '1' : '0');
echo "\n";
