<?php

declare(strict_types=1);

var_export(sscanf('abc123', '%[a-z]'));
echo "\n";
var_export(sscanf('abc123', '%[a-c]'));
echo "\n";
var_export(sscanf('xyz', '%[^0-9]'));
echo "\n";
