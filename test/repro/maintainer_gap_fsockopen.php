<?php

declare(strict_types=1);

$errno = 0;
$errstr = '';
$fp = @fsockopen('127.0.0.1', 9, $errno, $errstr, 1);
var_export([function_exists('fsockopen'), is_resource($fp), $errno, $errstr]);
