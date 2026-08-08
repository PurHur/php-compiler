<?php
// AOT-friendly: no closures (#28929)
error_reporting(E_ALL);
echo "body\n";
$got = @http_response_code(201);
$now = http_response_code();
echo 'got=';
var_export($got);
echo "\nnow=";
var_export($now);
echo "\n";
