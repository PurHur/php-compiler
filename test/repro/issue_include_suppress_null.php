<?php

declare(strict_types=1);

$missing = '/tmp/no_such_'.getmypid();
var_export(@include $missing);
echo "\n";
var_export(@include_once $missing);
echo "\n";
