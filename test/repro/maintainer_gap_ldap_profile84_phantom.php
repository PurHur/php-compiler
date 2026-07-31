<?php

declare(strict_types=1);

// Maintainer repro: #24536 — ldap must stay withheld under PROFILE=8.4 when host lacks php-ldap.
echo 'ext=', extension_loaded('ldap') ? '1' : '0', "\n";
echo 'fn=', function_exists('ldap_connect') ? '1' : '0', "\n";
$c = get_defined_constants(true);
echo 'bucket=', isset($c['ldap']) ? '1' : '0', "\n";
