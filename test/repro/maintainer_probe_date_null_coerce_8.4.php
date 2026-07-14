<?php
// #18902 / #18903 — nullable date format + datetime coerce on 8.4 profile (php-src-strict).
// Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_probe_date_null_coerce_8.4.php

echo 'date(null)=[' . date(null) . "]\n";
echo 'gmdate(null)=[' . gmdate(null) . "]\n";
$dt = date_create(null);
echo 'date_create(null)=' . (false === $dt ? 'false' : get_class($dt)) . "\n";
$dti = date_create_immutable(null);
echo 'date_create_immutable(null)=' . (false === $dti ? 'false' : get_class($dti)) . "\n";
