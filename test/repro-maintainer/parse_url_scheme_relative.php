<?php

declare(strict_types=1);

var_export(parse_url('//example.com/path'));
echo "\n";
echo parse_url('//example.com/path', PHP_URL_HOST), "\n";
echo parse_url('//example.com/path', PHP_URL_PATH), "\n";
