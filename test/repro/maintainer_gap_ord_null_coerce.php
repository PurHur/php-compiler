<?php
// #18821 — ord(null) coerces to 0 like Zend (even on 8.4 forward profile).
// Run with: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_ord_null_coerce.php

echo 'ord(null)=', ord(null), "\n";
