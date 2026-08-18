<?php

declare(strict_types=1);

/**
 * Repro for #32119 — locale_filter_matches() JIT must match php-src/VM prefix filter,
 * not throw RuntimeException("JIT lowering not implemented").
 *
 * Force-registers Locale + procedural builtin so the probe runs without host php-intl.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use PHPCompiler\Runtime;
use PHPCompiler\ext\intl\BuiltinClasses;

$runtime = new Runtime();
BuiltinClasses::registerLocale($runtime->vmContext);
$runtime->vmContext->declareFunction(new PHPCompiler\ext\intl\locale_filter_matches());

$code = <<<'PHP'
<?php
echo 'filter_pos=', locale_filter_matches('de-DE', 'de', false) ? 'true' : 'false', "\n";
echo 'filter_named=', locale_filter_matches(
    languageTag: 'de-DE',
    locale: 'de',
    canonicalize: true
) ? 'true' : 'false', "\n";
$tag = 'de-DE';
$locale = 'de';
echo 'filter_vars=', locale_filter_matches($tag, $locale, true) ? 'true' : 'false', "\n";
echo 'kw_f=', (int) locale_filter_matches('en_US@currency=usd', 'en_US', false), "\n";
echo 'kw_t=', (int) locale_filter_matches('en_US@currency=usd', 'en_US', true), "\n";
PHP;

$block = $runtime->parseAndCompile($code, 'issue_32119_locale_filter_matches_jit.php');
$runtime->run($block);
