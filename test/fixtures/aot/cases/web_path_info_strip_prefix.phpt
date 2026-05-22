--TEST--
AOT: strpos/substr on $_SERVER PATH_INFO (MiniWebApp route strip)
--ENV--
SCRIPT_NAME=/index.php
PATH_INFO=/hello
REQUEST_METHOD=GET
--FILE--
<?php
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if ('' !== $pathInfo) {
    if (0 === strpos($pathInfo, '/')) {
        $pathInfo = substr($pathInfo, 1);
    }
    echo 'route:', $pathInfo, "\n";
}
--EXPECT--
route:hello
--EXPECT_EXIT--
0
