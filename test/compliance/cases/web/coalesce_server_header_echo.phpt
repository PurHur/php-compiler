--TEST--
Web: $_SERVER coalesce assign before header() must not suppress echo (#9225, 009-FastCGIWeb)
--FILE--
<?php
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
header('Content-Type: text/plain; charset=UTF-8');
if ($pathInfo !== '') {
    echo "path\n";
} else {
    echo "ok\n";
}
--EXPECT--
ok
