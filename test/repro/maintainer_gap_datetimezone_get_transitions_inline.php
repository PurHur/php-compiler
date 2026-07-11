<?php

declare(strict_types=1);

$tz = new DateTimeZone('Europe/Berlin');
$trans = $tz->getTransitions(strtotime('2024-01-01'), strtotime('2024-06-01'));
if (!is_array($trans)) {
    echo "not_array\n";
    exit(1);
}
echo count($trans), "\n";
