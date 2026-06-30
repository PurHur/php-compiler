<?php

declare(strict_types=1);

echo method_exists(Closure::class, 'getCurrent') ? "FAIL: Closure::getCurrent exists on Zend 8.2 reference\n" : "ok\n";
