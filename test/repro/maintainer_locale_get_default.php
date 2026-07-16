<?php

declare(strict_types=1);

/**
 * Maintainer repro: Locale / locale_get_default() partial surface (#6696).
 *
 * extension_loaded('intl') stays false until full ICU (#11472); Locale advertises like Normalizer.
 */

if (\extension_loaded('intl')) {
    echo 'fail: extension_loaded(intl)=true without full ext/intl';
    exit(1);
}

if (!\function_exists('locale_get_default') || !\function_exists('locale_set_default')) {
    echo 'fail: locale_get_default/set_default missing';
    exit(1);
}

if (!\class_exists('Locale', false)) {
    echo 'fail: Locale class missing';
    exit(1);
}

if (!\method_exists('Locale', 'getPrimaryLanguage') || !\method_exists('Locale', 'getDisplayName')) {
    echo 'fail: Locale parser/display methods missing';
    exit(1);
}

$lang = \Locale::getPrimaryLanguage('en_US');
if ('en' !== $lang) {
    echo 'fail: getPrimaryLanguage='.$lang;
    exit(1);
}

echo "ok: Locale\n";
