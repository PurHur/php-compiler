--TEST--
Stdlib: get_headers() HTTP connect failure warns + false (#26705, ext/standard/head.c)
--FILE--
<?php
error_reporting(E_ALL);
$GLOBALS['gh_connect_warns'] = [];
function gh_connect_warn_capture(int $no, string $msg): bool
{
    $GLOBALS['gh_connect_warns'][] = $no . ':' . $msg;
    return true;
}
set_error_handler('gh_connect_warn_capture');
$r = get_headers('http://127.0.0.1:1/', false);
$warns = $GLOBALS['gh_connect_warns'];
echo 'ret=', var_export($r, true), "\n";
echo 'warn_count=', count($warns), "\n";
$ok = isset($warns[0])
    && str_starts_with($warns[0], '2:get_headers(http://127.0.0.1:1/): Failed to open stream:')
    && str_contains($warns[0], 'Connection refused');
echo 'warn_ok=', $ok ? '1' : '0', "\n";
if (!$ok && isset($warns[0])) {
    echo 'warn_msg=', $warns[0], "\n";
}
?>
--EXPECT--
ret=false
warn_count=1
warn_ok=1
