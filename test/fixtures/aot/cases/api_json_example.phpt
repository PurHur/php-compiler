--TEST--
AOT: examples/004-ApiJson (json_encode API response)
--FILE--
<?php
header('Content-Type: application/json');
http_response_code(200);
echo json_encode(['ok' => true, 'service' => 'php-compiler']);
--EXPECT--
Status: 200
Content-Type: application/json

{"ok":true,"service":"php-compiler"}
--EXPECT_EXIT--
0
