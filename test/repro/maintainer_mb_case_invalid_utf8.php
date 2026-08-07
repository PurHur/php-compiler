<?php
/** #28629 — case mapping substitutes illegal UTF-8 (php_unicode_convert_case). */
$s = "\xE9cole";
echo "ucfirst:", bin2hex(mb_ucfirst($s, "UTF-8")), "\n";
echo "upper:", bin2hex(mb_strtoupper($s, "UTF-8")), "\n";
echo "lower:", bin2hex(mb_strtolower($s, "UTF-8")), "\n";
echo "title:", bin2hex(mb_convert_case($s, MB_CASE_TITLE, "UTF-8")), "\n";
echo "scrub:", bin2hex(mb_scrub($s, "UTF-8")), "\n";
mb_substitute_character(0xFFFD);
echo "upper_fffd:", bin2hex(mb_strtoupper($s, "UTF-8")), "\n";
