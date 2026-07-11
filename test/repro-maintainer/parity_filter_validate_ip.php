<?php

declare(strict_types=1);

var_export(filter_var('127.0.0.1', FILTER_VALIDATE_IP));
echo "\n";
var_export(filter_var('not-an-ip', FILTER_VALIDATE_IP));
echo "\n";
