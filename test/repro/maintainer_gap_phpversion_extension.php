<?php
declare(strict_types=1);

$core = phpversion();
$pcre = phpversion('pcre');
echo $pcre !== false && $pcre === $core ? "pcre_same\n" : "pcre_bad\n";
echo phpversion('core') === $core ? "core_ok\n" : "core_bad\n";
echo phpversion('nonexistent_xyz_10969') === false ? "unknown_ok\n" : "unknown_bad\n";
