<?php
var_export(base64_decode('YQ'));
echo "\n";
var_export(base64_decode('YQ', true));
echo "\n";
var_export(base64_decode('YQ==', true));
echo "\n";
var_export(base64_decode('YQ=a', true));
echo "\n";
var_export(base64_decode('YQ!!', true));
echo "\n";
