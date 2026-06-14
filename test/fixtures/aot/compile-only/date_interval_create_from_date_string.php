<?php
// AOT compile-only (#4606): date_interval_create_from_date_string() JIT lowering.
$iv = date_interval_create_from_date_string('1 day');
echo $iv instanceof DateInterval ? 'ok' : 'bad', "\n";
echo date_interval_create_from_date_string('1 day 2 hours')->d, ':', date_interval_create_from_date_string('1 day 2 hours')->h, "\n";
