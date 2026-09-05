<?php
// Discarded json_last_error*/preg_last_error*/date_default_timezone_get/
// timezone_version_get/stream_get_*/cli_get_process_title must match Zend
// (#36386). Side-effect-free statements only — results unused except shape
// checks on live builtins that compile/run cleanly under AOT.
// php-src: ext/json/json.c, ext/pcre/php_pcre.c, ext/date/php_date.c,
// ext/standard/streamsfuncs.c, ext/standard/cli_ops.c
// @differential-repeat: 3
function work(int $loops): int
{
    $c = 0;
    for ($k = 0; $k < $loops; ++$k) {
        json_last_error();
        json_last_error_msg();
        preg_last_error();
        preg_last_error_msg();
        date_default_timezone_get();
        timezone_version_get();
        stream_get_wrappers();
        stream_get_transports();
        stream_get_filters();
        cli_get_process_title();
        $c += $k;
    }

    $j = json_last_error();
    $jm = json_last_error_msg();
    $p = preg_last_error();
    $pm = preg_last_error_msg();
    $tz = date_default_timezone_get();
    $tv = timezone_version_get();
    $w = stream_get_wrappers();
    $t = stream_get_transports();
    $f = stream_get_filters();
    $cli = cli_get_process_title();

    return $c
        + (is_int($j) ? 1 : 0)
        + (is_string($jm) ? 1 : 0)
        + (is_int($p) ? 1 : 0)
        + (is_string($pm) ? 1 : 0)
        + (is_string($tz) && $tz !== '' ? 1 : 0)
        + (is_string($tv) && $tv !== '' ? 1 : 0)
        + (is_array($w) && count($w) > 0 ? 1 : 0)
        + (is_array($t) && count($t) > 0 ? 1 : 0)
        + (is_array($f) ? 1 : 0)
        + (is_string($cli) ? 1 : 0);
}
echo work(5), "\n";
echo work(3), "\n";
echo work(2), "\n";
