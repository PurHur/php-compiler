<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

use PHPCompiler\Runtime;
use PHPCompiler\ext\intl\BuiltinClasses;
use PHPCompiler\ext\intl\IntlExtensionPolicy;

$runtime = new Runtime();
if (!IntlExtensionPolicy::advertisesBuiltins()) {
    BuiltinClasses::registerUConverter($runtime->vmContext);
}

$code = <<<'PHP'
<?php
declare(strict_types=1);

var_export(class_exists('UConverter'));
echo "\n";

$out = UConverter::transcode('café', 'ISO-8859-1', 'UTF-8');
var_export($out);
echo "\n";
var_export($out === "caf\xE9");
echo "\n";

enum Es: string { case B = 'x'; }
try {
    UConverter::transcode(Es::B, 'UTF-8', 'ISO-8859-1');
    echo "enum uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
PHP;

$block = $runtime->parseAndCompile($code, 'maintainer_uconverter_transcode.php');
$runtime->run($block);
