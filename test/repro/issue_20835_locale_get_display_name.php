<?php

declare(strict_types=1);

// Issue #20835 — locale_get_display_name procedural alias.
echo 'oop='.Locale::getDisplayName('de_DE', 'en')."\n";
echo 'locale_get_display_language='.(function_exists('locale_get_display_language') ? 'yes' : 'no')."\n";
echo 'locale_get_display_name='.(function_exists('locale_get_display_name') ? 'yes' : 'no')."\n";
if (function_exists('locale_get_display_name')) {
    echo 'proc='.locale_get_display_name('de_DE', 'en')."\n";
}
