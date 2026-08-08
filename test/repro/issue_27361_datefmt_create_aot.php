<?php
// Issue #27361 — datefmt_create AOT must format en_US SHORT/NONE (re-#20837).
$f = datefmt_create('en_US', IntlDateFormatter::SHORT, IntlDateFormatter::NONE);
echo $f->format(strtotime('2024-02-29')), PHP_EOL;
