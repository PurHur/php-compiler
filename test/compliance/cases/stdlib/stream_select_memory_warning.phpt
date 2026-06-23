--TEST--
stdlib stream_select() php://memory — E_WARNING before ValueError (#10613, ext/standard/streams.c)
--FILE--
<?php
$r = [];
$w = [fopen('php://memory', 'r+')];
$e = [];
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
try {
    stream_select($r, $w, $e, 0);
} catch (Throwable $ex) {
    echo get_class($ex), "\n";
}
echo 'warnings=', count($warnings), "\n";
if ($warnings) {
    echo str_contains($warnings[0], 'MEMORY') ? 'warn_ok' : 'warn_bad', "\n";
}
?>
--EXPECT--
ValueError
warnings=1
warn_ok
