<?php
/**
 * #28347 — AOT runtime probe: md5_file/sha1_file return string|false on missing path
 * (Reflection method dispatch under AOT is separate; this guards the |false behavior).
 */
$path = tempnam(sys_get_temp_dir(), 'm28347');
file_put_contents($path, 'x');
$m = md5_file($path);
echo 'md5_ok=', is_string($m) ? '1' : '0', "\n";
$s = sha1_file($path);
echo 'sha1_ok=', is_string($s) ? '1' : '0', "\n";
@unlink($path);
$missing = @md5_file('/no/such/file/28347');
echo 'md5_missing=', (false === $missing) ? 'false' : gettype($missing), "\n";
$missing2 = @sha1_file('/no/such/file/28347');
echo 'sha1_missing=', (false === $missing2) ? 'false' : gettype($missing2), "\n";
