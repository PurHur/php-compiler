--TEST--
AOT: (string) cast on superglobal and scalar (MiniWebApp dispatch)
--FILE--
<?php
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
echo $method, "\n";
echo (string) 42, "\n";
--EXPECT--
GET
42
