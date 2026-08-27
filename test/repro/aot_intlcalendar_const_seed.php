<?php
// #35389 — ClassConstFetch must seed IntlCalendar::* for thin AOT (peer #35384).
echo 'FIELD_YEAR=', IntlCalendar::FIELD_YEAR, "\n";
echo 'FIELD_MONTH=', IntlCalendar::FIELD_MONTH, "\n";
echo 'DOW_SUNDAY=', IntlCalendar::DOW_SUNDAY, "\n";
