<?php

declare(strict_types=1);

/**
 * Repro for #25198 — locale_lookup()/locale_filter_matches() Reflection names
 * languageTag/defaultLocale and Zend named arguments (php-src php_intl.stub.php).
 *
 * Force-registers Locale + procedural builtins so the probe runs without host php-intl
 * (same pattern as test/repro/issue_20036_locale_lookup.php).
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use PHPCompiler\Runtime;
use PHPCompiler\ext\intl\BuiltinClasses;

$runtime = new Runtime();
BuiltinClasses::registerLocale($runtime->vmContext);
$runtime->vmContext->declareFunction(new PHPCompiler\ext\intl\locale_lookup());
$runtime->vmContext->declareFunction(new PHPCompiler\ext\intl\locale_filter_matches());

$code = <<<'PHP'
<?php
foreach (['locale_lookup', 'locale_filter_matches'] as $fn) {
    $rf = new ReflectionFunction($fn);
    echo $fn, ' arity=', $rf->getNumberOfParameters(), ' req=', $rf->getNumberOfRequiredParameters(), "\n";
    echo $fn, ' ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : '(none)', "\n";
    foreach ($rf->getParameters() as $p) {
        $t = $p->getType();
        echo '  ', ($t ? (string) $t.' ' : ''), '$', $p->getName();
        if ($p->isOptional()) {
            echo ' OPT';
            if ($p->isDefaultValueAvailable()) {
                echo '=', json_encode($p->getDefaultValue());
            }
        } else {
            echo ' REQ';
        }
        echo "\n";
    }
}

echo 'lookup_pos=', locale_lookup(['de-DE', 'de'], 'de-CH', true, 'en'), "\n";
echo 'lookup_named=', locale_lookup(
    languageTag: ['de-DE', 'de'],
    locale: 'de-CH',
    canonicalize: true,
    defaultLocale: 'en'
), "\n";
try {
    locale_lookup(langtag: ['de-DE'], locale: 'de', default: 'en');
    echo "legacy_lookup_ok\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}

echo 'filter_pos=', var_export(locale_filter_matches('de-DE', 'de', false), true), "\n";
echo 'filter_named=', var_export(
    locale_filter_matches(languageTag: 'de-DE', locale: 'de', canonicalize: false),
    true
), "\n";
try {
    locale_filter_matches(langtag: 'de-DE', locale: 'de');
    echo "legacy_filter_ok\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
PHP;

$block = $runtime->parseAndCompile($code, 'issue_25198_locale_lookup_named.php');
$runtime->run($block);
