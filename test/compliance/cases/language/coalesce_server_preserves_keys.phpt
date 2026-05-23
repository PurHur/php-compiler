--TEST--
Language: ?? on $_SERVER preserves unrelated keys (issue #1058)
--FILE--
<?php
$path = $_SERVER['PATH_INFO'] ?? '';
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
echo $method, ' ', $path, "\n";
--ENV--
REQUEST_METHOD=POST
PATH_INFO=/contact
SCRIPT_NAME=/index.php
--EXPECT--
POST /contact
