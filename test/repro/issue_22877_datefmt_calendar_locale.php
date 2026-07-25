<?php
/**
 * Repro #22877 — IntlDateFormatter @calendar= + TRADITIONAL formats non-Gregorian calendars.
 * Expect Zend-family strings (hebrew Tevet / islamic AH / japanese Shōwa / buddhist BE).
 *
 * Locale is assigned before new — inline encapsed + class-const args currently miscompile
 * NEW ARG_SEND order (separate language gap); calendar semantics are what this issue covers.
 */
foreach (['hebrew', 'islamic', 'japanese', 'buddhist'] as $cal) {
    $loc = "en_US@calendar=$cal";
    $df = new IntlDateFormatter(
        $loc,
        IntlDateFormatter::FULL,
        IntlDateFormatter::NONE,
        'UTC',
        IntlDateFormatter::TRADITIONAL
    );
    echo "$cal: " . $df->format(0) . "\n";
}
