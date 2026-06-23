--TEST--
stdlib json_encode() JIT float dtoa + JSON_PRESERVE_ZERO_FRACTION (#10797)
--FILE--
<?php
declare(strict_types=1);

echo json_encode(0.1 + 0.2), "\n";
echo json_encode(1.0, JSON_PRESERVE_ZERO_FRACTION), "\n";
--EXPECT--
0.30000000000000004
1.0
