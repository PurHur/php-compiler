<?php
// Repro for #6187 — ResourceBundle (+ forced registration when intl withheld)
require __DIR__ . '/../../vendor/autoload.php';

use PHPCompiler\Runtime;
use PHPCompiler\ext\intl\BuiltinClasses;
use PHPCompiler\ext\intl\IntlExtensionPolicy;

$runtime = new Runtime();
if (!IntlExtensionPolicy::advertisesResourceBundle()) {
    BuiltinClasses::registerResourceBundle($runtime->vmContext);
}

$code = <<<'PHP'
<?php
var_export(class_exists('ResourceBundle'));
echo "\n";
$rb = ResourceBundle::create('en', null);
echo $rb === false || $rb === null ? 'null' : 'obj', "\n";
$ver = $rb->get('Version');
echo is_string($ver) && $ver !== '' ? 'version_ok' : 'version_bad', "\n";
echo $ver, "\n";
PHP;

$block = $runtime->parseAndCompile($code, 'issue_6187_resourcebundle.php');
$runtime->run($block);
