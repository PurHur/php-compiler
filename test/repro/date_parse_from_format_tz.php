<?php
declare(strict_types=1);

// Repro #25487 — date_parse_from_format token T must emit zone_type/tz_abbr/tz_id.
$p = date_parse_from_format('Y-m-d H:i:s T', '2020-01-02 03:04:05 UTC');
echo implode('|', array_keys($p)), "\n";
echo 'tz_abbr=', var_export($p['tz_abbr'] ?? null, true),
    ' tz_id=', var_export($p['tz_id'] ?? null, true),
    ' zone_type=', var_export($p['zone_type'] ?? null, true), "\n";
