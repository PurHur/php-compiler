--TEST--
stream_context_set_option string-form incomplete args → ValueError (#30645)
--FILE--
<?php
$c = stream_context_create();
foreach ([
    static fn () => stream_context_set_option($c, 'http'),
    static fn () => stream_context_set_option($c, 'http', 'method'),
    static fn () => stream_context_set_option($c, ['http' => ['timeout' => 1]], 'method'),
] as $fn) {
    try {
        $r = $fn();
        echo 'NO_THROW ', var_export($r, true), "\n";
    } catch (ValueError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
var_export(stream_context_set_option($c, ['http' => ['method' => 'GET']]));
echo "\n";
var_export(stream_context_set_option($c, 'http', 'method', 'POST'));
echo "\n";
$opts = stream_context_get_options($c);
echo $opts['http']['method'], "\n";
?>
--EXPECT--
stream_context_set_option(): Argument #3 ($option_name) cannot be null when argument #2 ($wrapper_or_options) is a string
stream_context_set_option(): Argument #4 ($value) must be provided when argument #2 ($wrapper_or_options) is a string
stream_context_set_option(): Argument #3 ($option_name) must be null when argument #2 ($wrapper_or_options) is an array
true
true
POST
