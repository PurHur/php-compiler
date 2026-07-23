<?php
/** Issue #22623 — AOT: IntlDateFormatter::PATTERN value + construct (no Reflection). */
echo IntlDateFormatter::PATTERN, PHP_EOL;
$f = new IntlDateFormatter('en_US', IntlDateFormatter::PATTERN, IntlDateFormatter::PATTERN, 'UTC', null, 'yyyy-MM-dd');
echo $f->format(1579046400), PHP_EOL;
echo $f->getPattern(), PHP_EOL;
