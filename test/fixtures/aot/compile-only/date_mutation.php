<?php
declare(strict_types=1);

$dt = new DateTime('2026-06-01 12:00:00', new DateTimeZone('UTC'));
date_add($dt, new DateInterval('P1D'));
date_modify($dt, '+2 days');
date_sub($dt, new DateInterval('P1D'));
date_diff(new DateTime('2026-06-01'), new DateTime('2026-06-03'));
