<?php

declare(strict_types=1);

// php-src: null coerces to "" before FILTER_VALIDATE_BOOLEAN (#17238).
$r1 = filter_var(null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
echo $r1 === false ? "null_false\n" : "null_bad:".var_export($r1, true)."\n";

$r2 = filter_var(@$x, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
echo $r2 === false ? "undef_false\n" : "undef_bad:".var_export($r2, true)."\n";

$r3 = filter_var('', FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
echo $r3 === false ? "empty_false\n" : "empty_bad:".var_export($r3, true)."\n";

$r4 = filter_var('not-bool', FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
echo null === $r4 ? "invalid_null\n" : "invalid_bad:".var_export($r4, true)."\n";

echo "ok\n";
