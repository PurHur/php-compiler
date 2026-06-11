<?php

declare(strict_types=1);

echo function_exists('mktime') ? "mktime=yes\n" : "mktime=no\n";
echo mktime(0, 0, 0, 5, 29, 2026), "\n";
echo mktime(22, 13, 20, 11, 14, 2023), "\n";
