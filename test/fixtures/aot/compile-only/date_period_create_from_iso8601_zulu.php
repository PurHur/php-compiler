<?php

declare(strict_types=1);

// AOT compile-only: DatePeriod::createFromISO8601String() Zulu start/duration/end (#17280).
$p = DatePeriod::createFromISO8601String('2020-01-01T00:00:00Z/P1D/2020-01-05T00:00:00Z');
echo "ok\n";
