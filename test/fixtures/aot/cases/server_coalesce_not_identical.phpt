--TEST--
AOT: ($_SERVER['PATH_INFO'] ?? '') !== '' routing (#2477)
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
--EXPECT--
route:/ping
