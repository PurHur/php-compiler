<?php
/**
 * #22564 — on PROFILE=8.4 with ICU/intl loaded, grapheme_str_contains is present.
 * Without intl this prints 0 (see grapheme_profile_84.phpt).
 */
echo 'fe=', function_exists('grapheme_str_contains') ? '1' : '0', "\n";
echo 'callable=', is_callable('grapheme_str_contains') ? '1' : '0', "\n";
if (function_exists('grapheme_str_contains')) {
    echo 'call=', grapheme_str_contains('abc', 'b') ? '1' : '0', "\n";
}
