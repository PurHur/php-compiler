--TEST--
datefmt_format_object() Reflection + named datetime/format/locale (#25200)
--SKIPIF--
<?php if (!extension_loaded('intl')) die('skip host php-intl required'); ?>
--FILE--
<?php
$dt = new DateTime('2024-01-15 12:00:00', new DateTimeZone('UTC'));
$rf = new ReflectionFunction('datefmt_format_object');
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
    echo 'named=', datefmt_format_object(datetime: $dt, format: 'yyyy-MM-dd', locale: 'en_US'), "\n";
} catch (Throwable $e) {
    echo 'named=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    datefmt_format_object(object: $dt);
    echo "legacy_object accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
echo 'pos=', datefmt_format_object($dt, 'yyyy-MM-dd', 'en_US'), "\n";
?>
--EXPECT--
arity=3 req=1
ret=string|false
  $datetime REQ
  $format OPT=null
  ?string $locale OPT=null
named=2024-01-15
Unknown named parameter $object
pos=2024-01-15
