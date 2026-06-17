--TEST--
AOT: layout-style SCRIPT_NAME coalesce + htmlspecialchars (MiniWebApp nav) @group miniwebapp-bisect
--ENV--
SCRIPT_NAME=/index.php
--FILE--
<?php
declare(strict_types=1);
$scriptBase = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
echo htmlspecialchars($scriptBase);
--EXPECT--
/index.php
--EXPECT_EXIT--
0
