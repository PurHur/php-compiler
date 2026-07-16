<?php
// Repro for #5747 — Collator::create/compare (+ forced registration when intl withheld)
require __DIR__ . '/../../vendor/autoload.php';

use PHPCompiler\Runtime;
use PHPCompiler\ext\intl\BuiltinClasses;
use PHPCompiler\ext\intl\IntlExtensionPolicy;
use PHPCompiler\ext\intl\collator_create;

$runtime = new Runtime();
if (!IntlExtensionPolicy::advertisesCollator()) {
    BuiltinClasses::registerCollator($runtime->vmContext);
    $runtime->vmContext->declareFunction(new collator_create());
}

$code = <<<'PHP'
<?php
var_export(class_exists('Collator'));
echo "\n";
var_export(function_exists('collator_create'));
echo "\n";
$c = Collator::create('en_US');
var_export($c->compare('a', 'b'));
echo "\n";
var_export($c->compare('b', 'a'));
echo "\n";
$arr = ['x' => 'c', 'y' => 'a', 'z' => 'b'];
$c->asort($arr);
echo implode(',', $arr), "\n";
$p = collator_create('en_US');
echo get_class($p), "\n";
PHP;

$block = $runtime->parseAndCompile($code, 'issue_5747_collator.php');
$runtime->run($block);
