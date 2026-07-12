<?php

declare(strict_types=1);

$dt = new DateTime('+1 day');
echo 'relative_ctor=', $dt->format('Y-m-d'), "\n";

$now = new DateTime('now');
echo 'now_ctor=', $now->format('Y-m-d'), "\n";

$abs = new DateTime('2026-07-12');
echo 'abs_ctor=', $abs->format('Y-m-d'), "\n";
