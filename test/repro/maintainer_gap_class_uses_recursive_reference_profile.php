<?php

declare(strict_types=1);

// Zend 8.2 reference profile: class_uses_recursive() is PHP 8.3+ (ext/standard/basic_functions.c).
echo function_exists('class_uses_recursive') ? "fail\n" : "ok\n";
