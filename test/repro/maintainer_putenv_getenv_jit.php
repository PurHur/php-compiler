<?php

putenv('PHPC_TEST_ENV=1');
var_export(getenv('PHPC_TEST_ENV'));
echo "\n";
putenv('PHPC_TEST_ENV');
var_export(getenv('PHPC_TEST_ENV'));
echo "\n";
putenv('PHPC_TEST_ENV=2');
var_export(getenv('PHPC_TEST_ENV'));
echo "\n";
