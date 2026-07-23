<?php
/** Issue #22623 — AOT: IntlDateFormatter::PATTERN advertisement (value use hits pre-existing AOT neg-int class-const crash). */
echo defined('IntlDateFormatter::PATTERN') ? '1' : '0', PHP_EOL;
