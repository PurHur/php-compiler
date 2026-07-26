<?php
declare(strict_types=1);

/**
 * #22621 — IntlDateFormatter::parseToCalendar is PHP 8.4+ only.
 */
echo 'PROFILE=', getenv('PHP_COMPILER_PROFILE') ?: '(default)', PHP_EOL;
echo 'parseToCalendar=', method_exists('IntlDateFormatter', 'parseToCalendar') ? 'Y' : 'N', PHP_EOL;
echo 'parse=', method_exists('IntlDateFormatter', 'parse') ? 'Y' : 'N', PHP_EOL;
