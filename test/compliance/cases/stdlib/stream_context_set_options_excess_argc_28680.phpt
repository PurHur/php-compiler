--TEST--
stream_context_set_options wrong argc → ArgumentCountError (#28680)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$ctx = stream_context_create();
foreach ([
    static fn () => stream_context_set_options(),
    static fn () => stream_context_set_options($ctx),
    static fn () => stream_context_set_options($ctx, ['http' => ['method' => 'GET']], 'x'),
] as $fn) {
    try {
        $fn();
        echo "NO_THROW\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
var_export(stream_context_set_options($ctx, ['http' => ['method' => 'GET']]));
echo "\n";
?>
--EXPECT--
stream_context_set_options() expects exactly 2 arguments, 0 given
stream_context_set_options() expects exactly 2 arguments, 1 given
stream_context_set_options() expects exactly 2 arguments, 3 given
true
