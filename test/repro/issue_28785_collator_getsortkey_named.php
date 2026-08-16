<?php
/**
 * Collator::getSortKey Reflection + named $string (#28785).
 *
 * php-src: ext/intl/collator/collator.stub.php
 *   public function getSortKey(string $string): string|false
 *
 * Force-registers Collator when host php-intl is absent (Docker #22691).
 */
require __DIR__ . '/../../vendor/autoload.php';

use PHPCompiler\Runtime;
use PHPCompiler\ext\intl\BuiltinClasses;
use PHPCompiler\ext\intl\IntlExtensionPolicy;
use PHPCompiler\ext\intl\collator_get_sort_key;

$runtime = new Runtime();
if (!IntlExtensionPolicy::advertisesCollator()) {
    BuiltinClasses::registerCollator($runtime->vmContext);
    $runtime->vmContext->declareFunction(new collator_get_sort_key());
}

$code = <<<'PHP'
<?php
$r = new ReflectionMethod(Collator::class, 'getSortKey');
echo 'arity=', $r->getNumberOfParameters(), PHP_EOL;
echo 'req=', $r->getNumberOfRequiredParameters(), PHP_EOL;
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' ', ($p->hasType() ? (string) $p->getType() : 'none'), PHP_EOL;
}
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', PHP_EOL;
$c = new Collator('en_US');
try {
    $k = $c->getSortKey(string: 'abc');
    echo 'named_string=', is_string($k) ? 'string' : gettype($k), PHP_EOL;
} catch (Throwable $e) {
    echo 'named_string=', get_class($e), ': ', $e->getMessage(), PHP_EOL;
}
try {
    $c->getSortKey(str: 'abc');
    echo "legacy_str accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
$pos = $c->getSortKey('abc');
echo 'pos=', is_string($pos) ? 'string' : gettype($pos), PHP_EOL;
PHP;

$block = $runtime->parseAndCompile($code, 'issue_28785_collator_getsortkey_named.php');
$runtime->run($block);
