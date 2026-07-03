<?php
// Issue #11336 — date_sun_info() inline strtotime() timestamp (ext/date/php_date.c)
echo json_encode(date_sun_info(strtotime('2020-06-21'), 51.5, -0.1)) . "\n";
