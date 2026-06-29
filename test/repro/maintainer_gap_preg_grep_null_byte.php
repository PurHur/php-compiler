<?php

declare(strict_types=1);

var_export(preg_grep('/\0/', ["a\0b", 'c']));
echo "\n";
