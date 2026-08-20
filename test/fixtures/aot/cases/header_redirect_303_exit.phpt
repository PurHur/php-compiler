--TEST--
AOT: header() Location 303 + exit flushes CGI Status (005-SessionsWeb POST, #1974)
--FILE--
<?php
declare(strict_types=1);
header('Location: /example.php', true, 303);
exit;
--ENV--
REQUEST_METHOD=POST
GATEWAY_INTERFACE=CGI/1.1
--EXPECT--
Status: 303
Location: /example.php
