--TEST--
Language: (string) cast on superglobal and scalar (#740 MiniWebApp dispatch)
--FILE--
<?php
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
echo $method, "\n";
echo (string) 42, "\n";
echo (string) true, "\n";
--EXPECT--
GET
42
1
