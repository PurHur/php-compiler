--TEST--
stream_context_create() non-array options/params throw TypeError (#4627, ext/standard/streams.c)
--FILE--
<?php
$ctx = stream_context_create(['http' => ['timeout' => 5]]);
echo is_array($ctx) || is_resource($ctx) ? "ok\n" : "bad\n";

foreach ([new stdClass(), 'not-array', 1] as $bad) {
    try {
        stream_context_create($bad);
        echo "no throw\n";
    } catch (Throwable $e) {
        echo get_class($e), "\n";
    }
}

try {
    stream_context_create([], new stdClass());
    echo "params no throw\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
ok
TypeError
TypeError
TypeError
TypeError
