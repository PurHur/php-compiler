--TEST--
normalizer_get_raw_decomposition() Reflection + named string/form (#27705)
--SKIPIF--
<?php if (!extension_loaded('intl')) die('skip host php-intl required'); ?>
--FILE--
<?php
$rf = new ReflectionFunction('normalizer_get_raw_decomposition');
echo 'arity=', $rf->getNumberOfParameters(), ' req=', $rf->getNumberOfRequiredParameters(), "\n";
echo 'ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : '(none)', "\n";
foreach ($rf->getParameters() as $p) {
    $t = $p->getType();
    echo '  ', ($t ? (string) $t.' ' : ''), '$', $p->getName();
    if ($p->isOptional()) {
        echo ' OPT';
        if ($p->isDefaultValueAvailable()) {
            echo '=', json_encode($p->getDefaultValue());
        }
    } else {
        echo ' REQ';
    }
    echo "\n";
}
try {
    $raw = normalizer_get_raw_decomposition(string: "\xC3\xA9");
    echo 'named_string=', null === $raw ? 'null' : bin2hex($raw), "\n";
} catch (Throwable $e) {
    echo 'named_string=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $raw = normalizer_get_raw_decomposition(string: 'a', form: Normalizer::FORM_C);
    echo 'named_form=', null === $raw ? 'null' : bin2hex($raw), "\n";
} catch (Throwable $e) {
    echo 'named_form=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    normalizer_get_raw_decomposition(input: 'x');
    echo "legacy_input accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
arity=2 req=1
ret=?string
  string $string REQ
  int $form OPT=16
named_string=65cc81
named_form=null
Unknown named parameter $input
