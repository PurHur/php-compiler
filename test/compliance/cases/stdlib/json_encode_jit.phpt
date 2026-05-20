--TEST--
stdlib json_encode() JIT (issue #61)
--FILE--
<?php
echo json_encode(['ok' => true, 'service' => 'php-compiler']);
--EXPECT--
{"ok":true,"service":"php-compiler"}
