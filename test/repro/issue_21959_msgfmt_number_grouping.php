<?php
/**
 * Repro for #21959 — MessageFormatter {0,number} locale grouping (en_US → 1,234).
 * php-src: ext/intl/msgformat/msgformat_format.c → umsg_format / ICU number style.
 */
if (!class_exists('MessageFormatter')) {
    echo "SKIP\n";
    exit(0);
}
echo MessageFormatter::formatMessage('en_US', '{0,number}', [1234]), "\n";
$f = MessageFormatter::create('en_US', '{0,number}');
echo $f->format([1234]), "\n";
echo MessageFormatter::formatMessage('en_US', '{0,number}', [1234.5]), "\n";
echo MessageFormatter::formatMessage('de_DE', '{0,number}', [1234]), "\n";
