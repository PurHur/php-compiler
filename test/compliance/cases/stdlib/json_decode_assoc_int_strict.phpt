--TEST--
stdlib json_decode() int $assoc TypeError under strict_types (issue #11754)
--FILE--
<?php
declare(strict_types=1);
try {
    json_decode('[]', 1);
    echo "no_error\n";
} catch (TypeError $e) {
    echo str_contains($e->getMessage(), 'bool') ? "type_error\n" : "bad_msg\n";
}
--EXPECT--
type_error
