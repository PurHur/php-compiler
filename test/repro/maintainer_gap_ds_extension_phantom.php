<?php

declare(strict_types=1);

// Maintainer repro: #25086 — ds phantom on default profile (Zend without pecl-ds).
echo 'ext=', extension_loaded('ds') ? '1' : '0', "\n";
echo 'Vector=', class_exists('Ds\\Vector', false) ? '1' : '0', "\n";
