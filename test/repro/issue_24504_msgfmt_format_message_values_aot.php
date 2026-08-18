<?php
/**
 * #24504 — AOT probe: msgfmt_format_message named values:
 * php-src: ext/intl/msgformat/msgformat.stub.php
 *
 * When host php-intl is absent the compiler withholds the symbol (php-src-strict
 * #19670). With intl loaded, named values: must format and $args must be unknown.
 *
 *   ./script/docker-exec.sh -- bash -lc 'source script/php-env.sh && php bin/compile.php -o /tmp/issue-24504 test/repro/issue_24504_msgfmt_format_message_values_aot.php && /tmp/issue-24504'
 */
if (!function_exists('msgfmt_format_message')) {
    echo "MISSING\n";
    exit(0);
}
$rf = new ReflectionFunction('msgfmt_format_message');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";
echo msgfmt_format_message(locale: 'en_US', pattern: 'Hi {0}', values: ['Ada']), "\n";
