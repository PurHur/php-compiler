<?php

declare(strict_types=1);

var_export(preg_filter('/\0/', 'X', ["a\0b", 'c']));
echo "\n";
