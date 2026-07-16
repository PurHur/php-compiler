<?php
// Repro for #6366 — MessageFormatter/msgfmt_* (+ forced registration when intl withheld)
require __DIR__ . '/../../vendor/autoload.php';

use PHPCompiler\Runtime;
use PHPCompiler\ext\intl\BuiltinClasses;
use PHPCompiler\ext\intl\IntlExtensionPolicy;
use PHPCompiler\ext\intl\msgfmt_create;
use PHPCompiler\ext\intl\msgfmt_format;
use PHPCompiler\ext\intl\msgfmt_format_message;

$runtime = new Runtime();
if (!IntlExtensionPolicy::advertisesMessageFormatter()) {
    BuiltinClasses::registerMessageFormatter($runtime->vmContext);
    $runtime->vmContext->declareFunction(new msgfmt_create());
    $runtime->vmContext->declareFunction(new msgfmt_format());
    $runtime->vmContext->declareFunction(new msgfmt_format_message());
}

$code = <<<'PHP'
<?php
var_export(class_exists('MessageFormatter'));
echo "\n";
var_export(function_exists('msgfmt_create'));
echo "\n";
$fmt = msgfmt_create('en_US', '{0, number} files');
echo msgfmt_format($fmt, [3]), "\n";
$oop = MessageFormatter::create('en_US', '{name}');
$oop->setPattern('{name} uploaded');
echo $oop->format(['name' => 'doc']), "\n";
echo msgfmt_format_message('en_US', '{0} x', [1]), "\n";
PHP;

$block = $runtime->parseAndCompile($code, 'issue_6366_msgfmt.php');
$runtime->run($block);
