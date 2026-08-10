<?php
// AOT compile+run #29348 — soft-null $start/$end coerce (DEP skipped on user-script AOT fold)
error_reporting(E_ALL & ~E_DEPRECATED);
echo implode(',', range(null, 3)), "\n";
echo implode(',', range(0, null)), "\n";
