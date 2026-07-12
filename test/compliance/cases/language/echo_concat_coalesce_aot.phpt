--TEST--
language: AOT echo concat chain with embedded ?? does not heap-corrupt (#17522)
--FILE--
<?php
declare(strict_types=1);

$pathInfo = $_SERVER['PATH_INFO'] ?? '';
echo 'REQUEST_URI='.($_SERVER['REQUEST_URI'] ?? '/')."\n"
    .'SCRIPT_NAME='.($_SERVER['SCRIPT_NAME'] ?? '/example.php')."\n"
    .'PATH_INFO='.$pathInfo."\n";
--ENV--
PATH_INFO=/ping
SCRIPT_NAME=/example.php
REQUEST_URI=/example.php/ping
--EXPECT--
REQUEST_URI=/example.php/ping
SCRIPT_NAME=/example.php
PATH_INFO=/ping
