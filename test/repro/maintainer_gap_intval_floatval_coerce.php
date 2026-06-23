<?php

declare(strict_types=1);

/**
 * Issue #10810: intval()/floatval() array/object/resource coercion (ext/standard/type.c).
 */

echo 'intval([]): ', intval([]), "\n";
echo 'floatval([]): ', floatval([]), "\n";
echo 'intval([1]): ', intval([1]), "\n";
echo 'floatval([1]): ', floatval([1]), "\n";
echo 'intval(obj): ', @intval(new stdClass()), "\n";
@intval(new stdClass());
$err = error_get_last();
echo 'warning: ', $err['message'] ?? '', "\n";
$r = fopen('php://memory', 'r');
$resId = @intval($r);
echo 'intval(res): ', $resId > 0 ? 'positive' : 'zero', "\n";
