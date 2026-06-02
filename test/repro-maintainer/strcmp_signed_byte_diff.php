<?php

declare(strict_types=1);

// Issue #4345 — strcmp family returns signed byte difference (php-src zend_binary_strcmp).
echo strncmp('a', 'A', 1), "\n";
echo strncmp('a', 'b', 1), "\n";
echo strcmp('a', '1'), "\n";
echo strcasecmp('a', 'A'), "\n";
echo strncasecmp('a', 'A', 1), "\n";
