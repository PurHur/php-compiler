<?php

declare(strict_types=1);

/**
 * Repro for #26923 — StreamBucket under PHP_COMPILER_PROFILE=8.4.
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/stream_bucket_class_84.php
 */

$f = fopen('php://memory', 'r+');
$b = stream_bucket_new($f, 'hello');
echo 'class=', get_class($b), PHP_EOL;
echo 'exists=', class_exists('StreamBucket') ? 'yes' : 'no', PHP_EOL;
echo 'data=', $b->data, PHP_EOL;
echo 'datalen=', $b->datalen, PHP_EOL;
echo 'dataLength=', property_exists($b, 'dataLength') ? $b->dataLength : 'missing', PHP_EOL;
