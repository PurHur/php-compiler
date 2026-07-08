<?php

declare(strict_types=1);

// Issue #17456 — glob('/tmp/.*') must include . and .. (php-src ext/standard/dir.c)
$tmp = sys_get_temp_dir();
$matches = glob($tmp . '/.*');
$dot = $tmp . '/.';
$parent = $tmp . '/..';
$ok = is_array($matches)
    && in_array($dot, $matches, true)
    && in_array($parent, $matches, true);
echo $ok ? "ok\n" : "bad\n";
