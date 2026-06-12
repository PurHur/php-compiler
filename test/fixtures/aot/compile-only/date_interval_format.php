<?php
// Compile-only (#7278): DateInterval + date_interval_format VM builtins.
$interval = new DateInterval('P1D');
echo date_interval_format($interval, '%d'), "\n";
echo $interval->format('%y%m%d'), "\n";
