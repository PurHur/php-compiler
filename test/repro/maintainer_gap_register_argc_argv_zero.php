<?php

declare(strict_types=1);

// Issue #4515 — register_argc_argv=0 must omit $argc/$argv (php-src main.c).

echo 'ini_get='.ini_get('register_argc_argv')."\n";
echo 'ini_set='.var_export(ini_set('register_argc_argv', '0'), true)."\n";
echo 'argc='.(isset($argc) ? var_export($argc, true) : "'unset'")."\n";
echo 'argv_isset='.var_export(isset($argv), true)."\n";
