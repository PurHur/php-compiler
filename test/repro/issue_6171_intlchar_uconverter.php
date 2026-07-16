<?php
// Repro for #6171 — IntlChar::ord/chr + UConverter convert (forced registration when intl withheld)
require __DIR__ . '/../../vendor/autoload.php';

use PHPCompiler\Runtime;
use PHPCompiler\ext\intl\BuiltinClasses;
use PHPCompiler\ext\intl\IntlExtensionPolicy;

$runtime = new Runtime();
if (!IntlExtensionPolicy::advertisesBuiltins()) {
    BuiltinClasses::registerIntlChar($runtime->vmContext);
    BuiltinClasses::registerUConverter($runtime->vmContext);
}

$code = <<<'PHP'
<?php
echo 'IntlChar: ', class_exists('IntlChar') ? 'yes' : 'no', "\n";
echo 'UConverter: ', class_exists('UConverter') ? 'yes' : 'no', "\n";
echo 'Spoofchecker: ', class_exists('Spoofchecker') ? 'yes' : 'no', "\n";
echo IntlChar::ord('A'), "\n";
echo IntlChar::chr(65), "\n";
$u = new UConverter('ISO-8859-1', 'UTF-8');
echo bin2hex($u->convert("\xC3\xA9")), "\n";
echo bin2hex($u->convert("\xE9", true)), "\n";
PHP;

$block = $runtime->parseAndCompile($code, 'issue_6171_intlchar_uconverter.php');
$runtime->run($block);
