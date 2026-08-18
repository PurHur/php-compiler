<?php

declare(strict_types=1);

/**
 * Repro for #32118 — locale_lookup() JIT must match php-src/VM RFC 4647 lookup,
 * not throw RuntimeException("JIT lowering not implemented").
 *
 * Force-registers Locale + procedural builtin so the probe runs without host php-intl.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use PHPCompiler\Runtime;
use PHPCompiler\ext\intl\BuiltinClasses;

$runtime = new Runtime();
BuiltinClasses::registerLocale($runtime->vmContext);
$runtime->vmContext->declareFunction(new PHPCompiler\ext\intl\locale_lookup());

$code = <<<'PHP'
<?php
echo 'lookup_pos=', locale_lookup(['de-DE', 'de'], 'de-CH', true, 'en'), "\n";
echo 'lookup_named=', locale_lookup(
    languageTag: ['de-DE', 'de'],
    locale: 'de-CH',
    canonicalize: true,
    defaultLocale: 'en'
), "\n";
$tags = ['de-DE', 'de'];
$locale = 'de-CH';
echo 'lookup_vars=', locale_lookup($tags, $locale, true, 'en'), "\n";
echo 'lookup_fallback=', locale_lookup(['de-DE', 'fr-FR'], 'de-CH', false, 'en_US'), "\n";
PHP;

$block = $runtime->parseAndCompile($code, 'issue_32118_locale_lookup_jit.php');
$runtime->run($block);
