--TEST--
AOT: json_encode API body with Content-Type and Status 200 (issues #61, #270)
--FILE--
<?php
header('Content-Type: application/json');
http_response_code(200);
echo json_encode(['ok' => true, 'service' => 'php-compiler']);
--EXPECT--
Content-Type: application/json
Status: 200
{"ok":true,"service":"php-compiler"}
--EXPECT_EXIT--
0
