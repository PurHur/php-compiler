<?php
/**
 * Repro #25586 — normalizer_normalize Reflection must match
 * php-src normalizer.stub.php: (string $string, int $form = FORM_C): string|false
 * + named string:/form:; named input: rejected.
 *
 * Force-registers when host php-intl is absent (Docker #22691).
 *
 *   ./script/docker-exec.sh -- bash -lc 'php test/repro/issue_25586_normalizer_normalize_reflection.php'
 */
require __DIR__ . '/../../vendor/autoload.php';

use PHPCompiler\Runtime;
use PHPCompiler\ext\intl\BuiltinClasses;
use PHPCompiler\ext\intl\IntlExtensionPolicy;
use PHPCompiler\ext\intl\normalizer_normalize;

$runtime = new Runtime();
if (!IntlExtensionPolicy::advertisesNormalizer()) {
    BuiltinClasses::registerNormalizer($runtime->vmContext);
    $runtime->vmContext->declareFunction(new normalizer_normalize());
}

$code = <<<'PHP'
<?php
$r = new ReflectionFunction('normalizer_normalize');
$params = [];
foreach ($r->getParameters() as $p) {
    $t = $p->getType();
    $line = $p->getName().':'.($t ? (string) $t : 'none');
    if ($p->isOptional()) {
        $line .= ' opt';
        if ($p->isDefaultValueAvailable()) {
            $line .= '='.var_export($p->getDefaultValue(), true);
        }
    }
    $params[] = $line;
}
$rt = $r->getReturnType();
echo 'arity='.$r->getNumberOfParameters().'|'.implode(',', $params).'|'.($rt ? (string) $rt : 'none')."\n";
$s = "e\u{0301}";
try {
    echo 'named='.bin2hex(normalizer_normalize(string: $s))."\n";
} catch (Throwable $e) {
    echo 'named ERR='.$e->getMessage()."\n";
}
try {
    normalizer_normalize(input: $s);
    echo "legacy_input accepted\n";
} catch (Throwable $e) {
    echo 'legacy='.$e->getMessage()."\n";
}
echo 'positional='.bin2hex(normalizer_normalize($s))."\n";
PHP;

$block = $runtime->parseAndCompile($code, 'issue_25586_normalizer_normalize_reflection.php');
$runtime->run($block);
