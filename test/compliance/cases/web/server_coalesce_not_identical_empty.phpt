--TEST--
Web: ($_SERVER['PATH_INFO'] ?? '') !== '' is false when PATH_INFO unset (#2477)
--FILE--
<?php
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if ($pathInfo !== '') {
    echo "route\n";
} else {
    echo "health\n";
}
--ENV--
SCRIPT_NAME=/example.php
REQUEST_URI=/example.php
--EXPECT--
health
