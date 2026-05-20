--TEST--
Web: json_encode API body (issue #61, #270)
--FILE--
<?php
header('Content-Type: application/json');
http_response_code(200);
echo json_encode(['ok' => true, 'service' => 'php-compiler']);
--EXPECT--
{"ok":true,"service":"php-compiler"}
