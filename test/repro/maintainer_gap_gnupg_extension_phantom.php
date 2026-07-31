<?php

declare(strict_types=1);

// Maintainer repro: #25360 — gnupg phantom on default profile (Zend without pecl-gnupg).
echo 'ext=', extension_loaded('gnupg') ? '1' : '0', "\n";
echo 'fn=', function_exists('gnupg_init') ? '1' : '0', "\n";
echo 'cls=', class_exists('gnupg', false) ? '1' : '0', "\n";
