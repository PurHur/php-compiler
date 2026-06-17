<?php

declare(strict_types=1);

var_export(session_status());
echo ' ';
var_export(PHP_SESSION_NONE);
echo "\n";
var_export(session_status() === PHP_SESSION_NONE);
echo "\n";
