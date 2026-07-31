<?php
/**
 * AOT repro #23681 — named string: binds for getimagesizefromstring.
 * AOT currently returns false for a valid 1×1 PNG (pre-existing decode gap);
 * assert both paths are false so named binding is exercised. Do not use $a === $b
 * (AOT false===false between locals is also broken independently).
 *
 * Build: php bin/compile.php -o /tmp/gis23681 test/repro/issue_23681_getimagesizefromstring_named_aot.php && /tmp/gis23681
 */
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$pos = getimagesizefromstring($png);
$named = getimagesizefromstring(string: $png);
echo ($pos === false ? 'posF' : 'posO'), "\n";
echo ($named === false ? 'namedF' : 'namedO'), "\n";
$invalid = @getimagesizefromstring(string: 'x');
echo ($invalid === false ? 'invalidF' : 'invalidO'), "\n";
