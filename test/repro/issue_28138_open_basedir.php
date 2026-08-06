<?php
/**
 * Repro #28138 — open_basedir ini_set + path denial (php-src main/fopen_wrappers.c).
 *
 * Expect:
 *   ini_set returns previous ''
 *   ini_get returns the basedir
 *   file_get_contents / is_file / file_exists outside basedir → false
 *   file under basedir still readable
 */
error_reporting(E_ALL);
$prev = ini_set('open_basedir', '/tmp');
$got = ini_get('open_basedir');
echo 'set=' . var_export($prev, true) . "\n";
echo 'get=' . var_export($got, true) . "\n";

$warnings = [];
set_error_handler(static function (int $n, string $m) use (&$warnings): bool {
    $warnings[] = $m;
    return true;
});

$denied = file_get_contents('/etc/hosts') === false;
$isFile = is_file('/etc/hosts');
$exists = file_exists('/etc/hosts');
echo 'fgc_denied=' . var_export($denied, true) . "\n";
echo 'is_file=' . var_export($isFile, true) . "\n";
echo 'file_exists=' . var_export($exists, true) . "\n";

$tmp = tempnam('/tmp', 'obd28138_');
file_put_contents($tmp, 'ok');
$ok = file_get_contents($tmp);
echo 'fgc_ok=' . var_export($ok, true) . "\n";
@unlink($tmp);

$basedirWarns = 0;
foreach ($warnings as $w) {
    if (str_contains($w, 'open_basedir restriction in effect')) {
        $basedirWarns++;
    }
}
echo 'basedir_warns=' . $basedirWarns . "\n";

$pass = $prev === ''
    && $got !== '' && $got !== false
    && $denied
    && $isFile === false
    && $exists === false
    && $ok === 'ok'
    && $basedirWarns >= 3;
echo $pass ? "PASS\n" : "FAIL\n";
exit($pass ? 0 : 1);
