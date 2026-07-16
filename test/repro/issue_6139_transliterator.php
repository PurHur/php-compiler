<?php
// Repro for #6139 — Transliterator (+ forced registration when intl withheld)
require __DIR__ . '/../../vendor/autoload.php';

use PHPCompiler\Runtime;
use PHPCompiler\ext\intl\BuiltinClasses;
use PHPCompiler\ext\intl\IntlExtensionPolicy;
use PHPCompiler\ext\intl\transliterator_create;
use PHPCompiler\ext\intl\transliterator_transliterate;

$runtime = new Runtime();
if (!IntlExtensionPolicy::advertisesTransliterator()) {
    BuiltinClasses::registerTransliterator($runtime->vmContext);
    $runtime->vmContext->declareFunction(new transliterator_create());
    $runtime->vmContext->declareFunction(new transliterator_transliterate());
}

$code = <<<'PHP'
<?php
$tr = transliterator_create('Any-Latin; Latin-ASCII');
echo $tr === false || $tr === null ? 'null' : 'obj', "\n";
echo transliterator_transliterate($tr, 'café'), "\n";
$oop = Transliterator::create('Any-Latin; Latin-ASCII');
echo $oop->transliterate('café'), "\n";
$bad = transliterator_create('Not-A-Real-ID-XYZ');
echo $bad === false || $bad === null ? 'bad_null' : 'bad_obj', "\n";
PHP;

$block = $runtime->parseAndCompile($code, 'issue_6139_transliterator.php');
$runtime->run($block);
