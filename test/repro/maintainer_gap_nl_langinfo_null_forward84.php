<?php
// #19076 — nl_langinfo(null) must coerce to 0, warn, return false on 8.4 forward profile (Z_PARAM_LONG).
// Run with: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_nl_langinfo_null_forward84.php

$r = @nl_langinfo(null);
echo 'result=', var_export($r, true), "\n";
