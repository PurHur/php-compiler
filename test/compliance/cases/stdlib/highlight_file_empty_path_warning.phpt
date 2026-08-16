--TEST--
stdlib highlight_file()/show_source() empty path — E_WARNING then ValueError (#30514, ext/standard/url.c)
--FILE--
<?php
foreach (['highlight_file', 'show_source'] as $fn) {
    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });
    try {
        $fn('');
        echo $fn, ": miss\n";
    } catch (ValueError $e) {
        echo $fn, ':', $e->getMessage(), "\n";
    }
    echo $fn, ':warnings=', count($warnings), "\n";
    if ($warnings) {
        echo $fn, ':', (str_contains($warnings[0], "Failed opening '' for highlighting") ? 'warn_ok' : 'warn_bad'), "\n";
    }
    restore_error_handler();
}
?>
--EXPECT--
highlight_file:Path cannot be empty
highlight_file:warnings=1
highlight_file:warn_ok
show_source:Path cannot be empty
show_source:warnings=1
show_source:warn_ok
