<?php

declare(strict_types=1);

// Maintainer repro: #24134 — dba phantom on default profile (Zend without ext/dba).
echo 'ext=', extension_loaded('dba') ? '1' : '0', "\n";
echo 'fn=', function_exists('dba_open') ? '1' : '0', "\n";
echo 'conn=', class_exists('Dba\\Connection', false) ? '1' : '0', "\n";
