--TEST--
stdlib file builtins missing path — E_WARNING + false (#10442, #10441, #10547)
--FILE--
<?php
$path = '/no/such/phpc-fs-missing-warnings';
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
foreach ([
    'filemtime' => static fn () => filemtime($path),
    'filesize' => static fn () => filesize($path),
    'chmod' => static fn () => chmod($path, 0644),
    'unlink' => static fn () => unlink($path),
    'touch' => static fn () => touch($path),
    'file_get_contents' => static fn () => file_get_contents($path),
    'fopen' => static fn () => fopen($path, 'r'),
    'copy' => static fn () => copy($path, '/tmp/x'),
    'rename' => static fn () => rename($path, '/tmp/y'),
] as $fn => $cb) {
    $warnings = [];
    $r = $cb();
    echo $fn, ' ', count($warnings), ' ', var_export($r, true), "\n";
}
?>
--EXPECT--
filemtime 1 false
filesize 1 false
chmod 1 false
unlink 1 false
touch 1 false
file_get_contents 1 false
fopen 1 false
copy 1 false
rename 1 false
