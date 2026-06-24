<?php
declare(strict_types=1);

$core = phpversion();
$pcre = phpversion('pcre');
$json = phpversion('json');
$zlib = phpversion('zlib');
$std = phpversion('standard');
echo $pcre === $core ? "pcre_ok\n" : "pcre_bad\n";
echo $json === $core ? "json_ok\n" : "json_bad\n";
echo $zlib === $core ? "zlib_ok\n" : "zlib_bad\n";
echo $std === $core ? "std_ok\n" : "std_bad\n";
echo phpversion('nonexistent') === false ? "unknown_ok\n" : "unknown_bad\n";
