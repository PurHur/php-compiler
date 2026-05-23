--TEST--
AOT: layout-style SCRIPT_NAME coalesce + htmlspecialchars (MiniWebApp nav) @group miniwebapp-bisect
--ENV--
SCRIPT_NAME=/index.php
--FILE--
<?php
declare(strict_types=1);
$scriptBase = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
header('Content-Type: text/html; charset=UTF-8');
echo htmlspecialchars($scriptBase);
--EXPECT--
Content-Type: text/html; charset=UTF-8
/index.php
--EXPECT_EXIT--
0
