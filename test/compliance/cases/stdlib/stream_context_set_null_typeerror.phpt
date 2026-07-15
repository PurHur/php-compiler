--TEST--
stdlib stream_context_set_* null context — TypeError not by-ref Error (#19213, ext/standard/streams.c)
--FILE--
<?php
$calls = [
    fn () => stream_context_set_option(null, []),
    fn () => stream_context_set_params(null, []),
];
if (function_exists('stream_context_set_options')) {
    $calls[] = fn () => stream_context_set_options(null, []);
}
foreach ($calls as $i => $call) {
    try {
        $call();
        echo "no_throw:$i\n";
    } catch (TypeError $e) {
        echo str_contains($e->getMessage(), 'must be of type resource') ? "typeerror:$i\n" : "badmsg:$i\n";
    } catch (Throwable $e) {
        echo get_class($e), ":$i\n";
    }
}
?>
--EXPECT--
typeerror:0
typeerror:1
