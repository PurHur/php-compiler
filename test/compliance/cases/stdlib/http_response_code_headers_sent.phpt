--TEST--
stdlib http_response_code() after output warns and returns false (#28929, ext/standard/head.c)
--FILE--
<?php
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
echo "body\n";
$got = http_response_code(201);
$now = http_response_code();
echo 'got=';
var_export($got);
echo "\nnow=";
var_export($now);
echo "\n";
echo 'warnings=', count($warnings), "\n";
if ($warnings) {
    echo str_contains($warnings[0], 'Cannot set response code - headers already sent') ? 'warn_ok' : 'warn_bad', "\n";
}
--EXPECT--
body
got=false
now=false
warnings=1
warn_ok
