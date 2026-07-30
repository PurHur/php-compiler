<?php
// Repro for #25145 — ResourceBundle read_dimension (not ArrayAccess)
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
$b = ResourceBundle::create('en', 'ICUDATA-region', false);
try {
    $x = $b['Countries'];
    echo 'get=', get_class($x), "\n";
    echo 'same=', (int) (get_class($x) === get_class($b->get('Countries'))), "\n";
} catch (Throwable $e) {
    echo 'get=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $isset = isset($b['Countries']) ? 'Y' : 'N';
    echo 'isset=', $isset, "\n";
} catch (Throwable $e) {
    echo 'isset=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $b['Countries'] = 1;
    echo "set=ok\n";
} catch (Throwable $e) {
    echo 'set=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unset($b['Countries']);
    echo "unset=ok\n";
} catch (Throwable $e) {
    echo 'unset=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $y = $b[0];
    echo 'idx0=', is_object($y) ? get_class($y) : gettype($y), "\n";
} catch (Throwable $e) {
    echo 'idx0=', get_class($e), ':', $e->getMessage(), "\n";
}
PHP;

$block = $runtime->parseAndCompile($code, 'issue_25145_resourcebundle_dimension.php');
$runtime->run($block);
