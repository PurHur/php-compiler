<?php

declare(strict_types=1);

// Maintainer repro: #24909 — apcu phantom on default profile (Zend without pecl-APCu).
echo 'ext=', extension_loaded('apcu') ? '1' : '0', "\n";
echo 'fn=', function_exists('apcu_store') ? '1' : '0', "\n";
