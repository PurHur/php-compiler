<?php

declare(strict_types=1);

/**
 * Repro for #32120 — locale_get_display_name() JIT must match php-src/VM display string,
 * not throw RuntimeException("JIT lowering not implemented").
 *
 * Force-registers Locale + procedural builtin so the probe runs without host php-intl.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use PHPCompiler\Runtime;
use PHPCompiler\ext\intl\BuiltinClasses;

$runtime = new Runtime();
BuiltinClasses::registerLocale($runtime->vmContext);
$runtime->vmContext->declareFunction(new PHPCompiler\ext\intl\locale_get_display_name());

$code = <<<'PHP'
<?php
echo 'proc=', locale_get_display_name('de_DE', 'en'), "\n";
echo 'named=', locale_get_display_name(locale: 'de_DE', in_locale: 'en'), "\n";
$locale = 'de_DE';
$display = 'en';
echo 'vars=', locale_get_display_name($locale, $display), "\n";
PHP;

$block = $runtime->parseAndCompile($code, 'issue_32120_locale_get_display_name_jit.php');
$runtime->run($block);
