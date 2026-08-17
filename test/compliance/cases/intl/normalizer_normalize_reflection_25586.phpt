--TEST--
normalizer_normalize() Reflection + named string/form (#25586)
--SKIPIF--
<?php if (!extension_loaded('intl')) die('skip host php-intl required'); ?>
--FILE--
<?php
$rf = new ReflectionFunction('normalizer_normalize');
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
$s = "e\u{0301}";
try {
    echo 'named_string=', bin2hex(normalizer_normalize(string: $s)), "\n";
} catch (Throwable $e) {
    echo 'named_string=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    echo 'named_form=', bin2hex(normalizer_normalize(string: $s, form: Normalizer::FORM_C)), "\n";
} catch (Throwable $e) {
    echo 'named_form=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    echo 'positional=', bin2hex(normalizer_normalize($s)), "\n";
} catch (Throwable $e) {
    echo 'positional=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    normalizer_normalize(input: $s);
    echo "legacy_input accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
arity=2 req=1
ret=string|false
  string $string REQ
  int $form OPT=16
named_string=c3a9
named_form=c3a9
positional=c3a9
Unknown named parameter $input
