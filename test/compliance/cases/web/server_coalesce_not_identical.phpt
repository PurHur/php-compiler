--TEST--
Web: ($_SERVER['PATH_INFO'] ?? '') !== '' branch for front-controller routing (#2477)
--FILE--
<?php
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if ($pathInfo !== '') {
    echo "route:", $pathInfo, "\n";
} else {
    echo "health\n";
}
--ENV--
PATH_INFO=/ping
SCRIPT_NAME=/example.php
REQUEST_URI=/example.php/ping
--EXPECT--
route:/ping
