<?php

declare(strict_types=1);

/**
 * Repro for #20036 — Locale::lookup / filterMatches / acceptFromHttp.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use PHPCompiler\Runtime;
use PHPCompiler\ext\intl\BuiltinClasses;

$runtime = new Runtime();
BuiltinClasses::registerLocale($runtime->vmContext);

$code = <<<'PHP'
<?php
echo 'lookup=', Locale::lookup(['de-DEDE', 'de-DE', 'de', 'fr'], 'de-DE-1996'), "\n";
echo 'fallback=', Locale::lookup(['de-DE', 'fr-FR'], 'de-CH', false, 'en_US'), "\n";
echo 'filter=', (int) Locale::filterMatches('de-DE', 'de'), "\n";
echo 'accept=', Locale::acceptFromHttp('en-US,en;q=0.9,fr;q=0.8'), "\n";
PHP;

$block = $runtime->parseAndCompile($code, 'issue_20036_locale_lookup.php');
$runtime->run($block);
