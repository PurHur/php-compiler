--TEST--
stdlib json_decode() string $depth/$flags TypeError under strict_types JIT (issue #12336)
--JIT--
--FILE--
<?php
declare(strict_types=1);
try {
    json_decode('[]', true, '512');
    echo "depth_no_error\n";
} catch (TypeError $e) {
    echo str_contains($e->getMessage(), 'depth') ? "depth_type_error\n" : "depth_bad_msg\n";
}
try {
    json_decode('[]', true, 512, '1');
    echo "flags_no_error\n";
} catch (TypeError $e) {
    echo str_contains($e->getMessage(), 'flags') ? "flags_type_error\n" : "flags_bad_msg\n";
}
--EXPECT--
depth_type_error
flags_type_error
