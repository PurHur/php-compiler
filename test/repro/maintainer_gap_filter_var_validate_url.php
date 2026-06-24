<?php

declare(strict_types=1);

var_export(filter_var('https://example.com', FILTER_VALIDATE_URL));
echo "\n";
var_export(filter_var('http://127.0.0.1:8080/path?q=1#frag', FILTER_VALIDATE_URL));
echo "\n";
var_export(filter_var('ftp://example.com', FILTER_VALIDATE_URL));
echo "\n";
