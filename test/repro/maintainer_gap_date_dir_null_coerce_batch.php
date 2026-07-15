<?php
// #18868 / #18867 / #18869 — nullable date format + datetime + Z_PARAM_PATH null must coerce (php-src-strict).
// Run with: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_date_dir_null_coerce_batch.php

echo 'date(null)=[' . date(null) . "]\n";
echo 'gmdate(null)=[' . gmdate(null) . "]\n";
$dt = date_create(null);
echo 'date_create(null)=' . (false === $dt ? 'false' : get_class($dt)) . "\n";
$dti = date_create_immutable(null);
echo 'date_create_immutable(null)=' . (false === $dti ? 'false' : get_class($dti)) . "\n";
echo 'opendir(null)=' . var_export(@opendir(null), true) . "\n";
echo 'mkdir(null)=' . var_export(@mkdir(null), true) . "\n";
echo 'rmdir(null)=' . var_export(@rmdir(null), true) . "\n";
echo 'chdir(null)=' . var_export(@chdir(null), true) . "\n";
