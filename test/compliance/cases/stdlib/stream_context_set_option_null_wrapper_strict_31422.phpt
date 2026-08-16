--TEST--
stream_context_set_option null wrapper strict_types TypeError (#31422)
--FILE--
<?php
declare(strict_types=1);
try {
    stream_context_set_option(stream_context_create(), null, 'a', 'b');
    echo "strict_fail\n";
} catch (TypeError $e) {
    echo str_contains($e->getMessage(), 'must be of type array|string') ? "strict_ok\n" : "strict_msg\n";
}
--EXPECT--
strict_ok
