--TEST--
Web: $_SERVER['PATH_INFO'] ?? '' preserves other $_SERVER keys (#1058)
--FILE--
<?php
declare(strict_types=1);

putenv('REQUEST_METHOD=POST');
putenv('PATH_INFO=/contact');
putenv('SCRIPT_NAME=/index.php');

$pathInfo = $_SERVER['PATH_INFO'] ?? '';
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

echo $pathInfo, "\n", $method, "\n";
?>
--EXPECT--
/contact
POST
