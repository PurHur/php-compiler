<?php

declare(strict_types=1);

var_export(enum_exists('ResponseCode', false));
echo "\n";
var_export(http_response_code(404));
echo "\n";
var_export(http_response_code());
echo "\n";
