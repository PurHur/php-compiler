<?php
declare(strict_types=1);

/**
 * #22597 — IntlCalendar::setDate/setDateTime are PHP 8.4+ only.
 * Use string class names (JIT verify bug with ClassName::class under PROFILE=8.2).
 */
echo 'PROFILE=', getenv('PHP_COMPILER_PROFILE') ?: '(default)', PHP_EOL;
echo 'setDate=', method_exists('IntlCalendar', 'setDate') ? 'Y' : 'N', PHP_EOL;
echo 'setDateTime=', method_exists('IntlCalendar', 'setDateTime') ? 'Y' : 'N', PHP_EOL;
