<?php

declare(strict_types=1);

echo function_exists('nextafter') ? "FAIL: nextafter exists on Zend 8.2 reference\n" : "ok\n";
