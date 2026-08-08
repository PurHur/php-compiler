<?php
declare(strict_types=1);
/**
 * Same shape as spine {main} hitting TimezoneAbbreviationsData (#29111).
 */
defined('PHP_VERSION');
defined('PHP_OS');
defined('DIRECTORY_SEPARATOR');
defined('PHP_EOL');
defined('PHP_INT_MAX');
$tz = require __DIR__.'/../../../ext/standard/TimezoneAbbreviationsData.php';
echo count($tz), "\n";
