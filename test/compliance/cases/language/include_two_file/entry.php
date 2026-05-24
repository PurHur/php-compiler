<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require __DIR__ . '/helper.php';
echo include_two_file_label($config), "\n";
