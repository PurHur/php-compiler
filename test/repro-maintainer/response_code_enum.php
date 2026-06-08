<?php

declare(strict_types=1);

var_export(enum_exists('ResponseCode', false));
echo "\n";
var_export(ResponseCode::NotFound->name);
echo "\n";
var_export(ResponseCode::NotFound->value);
echo "\n";
var_export(http_response_code(ResponseCode::NotFound));
echo "\n";
var_export(http_response_code());
echo "\n";
var_export(http_response_code(500));
echo "\n";
var_export(http_response_code());
echo "\n";
