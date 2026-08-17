--TEST--
idn_to_ascii()/idn_to_utf8() Reflection flags/variant UTS46 + named flags (#25199)
--SKIPIF--
<?php
if (!function_exists('idn_to_ascii') || !function_exists('idn_to_utf8')) {
    die('skip idn builtins not advertised (libidn2/host intl missing)');
}
?>
--FILE--
<?php
declare(strict_types=1);

foreach (['idn_to_ascii', 'idn_to_utf8'] as $fn) {
    $rf = new ReflectionFunction($fn);
    echo $fn, " arity=", $rf->getNumberOfParameters(), " req=", $rf->getNumberOfRequiredParameters(), "\n";
    echo $fn, " ret=", $rf->hasReturnType() ? (string) $rf->getReturnType() : '(none)', "\n";
    foreach ($rf->getParameters() as $p) {
        $t = $p->getType();
        echo '  ', ($t ? (string) $t.' ' : ''), '$', $p->getName();
        if ($p->isPassedByReference()) {
            echo ' REF';
        }
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
}

try {
    echo 'named=', idn_to_ascii(domain: 'münchen.de', flags: IDNA_DEFAULT, variant: INTL_IDNA_VARIANT_UTS46), "\n";
} catch (Throwable $e) {
    echo 'named=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    idn_to_ascii(domain: 'example.com', options: IDNA_DEFAULT);
    echo "legacy_options accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
idn_to_ascii arity=4 req=1
idn_to_ascii ret=string|false
  string $domain REQ
  int $flags OPT=0
  int $variant OPT=1
  ?array $idna_info REF OPT=null
idn_to_utf8 arity=4 req=1
idn_to_utf8 ret=string|false
  string $domain REQ
  int $flags OPT=0
  int $variant OPT=1
  ?array $idna_info REF OPT=null
named=xn--mnchen-3ya.de
Unknown named parameter $options
