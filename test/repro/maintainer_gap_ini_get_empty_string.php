<?php

declare(strict_types=1);

// Maintainer gap #12178 — unset ini string directives return '' not false (php-src-strict).
var_export(ini_get('auto_prepend_file'));
echo "\n";
var_export(ini_get('error_log'));
