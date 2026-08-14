<?php

declare(strict_types=1);

// Maintainer repro: #26141 — redis advertisement matches host phpredis on reference profile.
echo 'ext=', extension_loaded('redis') ? '1' : '0', "\n";
echo 'class=', class_exists('Redis', false) ? '1' : '0', "\n";
echo 'RedisArray=', class_exists('RedisArray', false) ? '1' : '0', "\n";
