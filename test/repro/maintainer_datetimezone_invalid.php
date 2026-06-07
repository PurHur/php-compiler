<?php
// Repro for #7279 — invalid DateTimeZone id must throw DateInvalidTimeZoneException.
try {
    new DateTimeZone('Not/A/Timezone');
    echo "no throw\n";
} catch (DateInvalidTimeZoneException $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
