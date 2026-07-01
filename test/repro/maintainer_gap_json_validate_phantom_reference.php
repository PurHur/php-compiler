<?php

declare(strict_types=1);

// Zend 8.2 reference profile: json_validate undefined (#14708).
echo function_exists('json_validate') ? "fail\n" : "ok\n";
