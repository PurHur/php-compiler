--TEST--
never return type: call site does not fall through after throw in try (issue #4117)
--FILE--
<?php
function fail(): never {
    throw new Exception('x');
}
try {
    fail();
    echo "after\n";
} catch (Exception $e) {
    echo "caught\n";
}
--EXPECT--
caught
