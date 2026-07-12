<?php

declare(strict_types=1);

// Maintainer gap: readfile(php://output) must return -1 (Zend stdout sentinel, #18417).
var_export(readfile('php://output'));
echo "\n";
var_export(readfile('php://stdin'));
echo "\n";
var_export(readfile('php://memory'));
echo "\n";
