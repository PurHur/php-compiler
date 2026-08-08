--TEST--
ext date date_create_from_format Reflection types + returns (VM, issue #27773)
--FILE--
<?php
foreach (['date_create_from_format', 'date_create_immutable_from_format', 'date_modify'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none';
    echo ' req=', $r->getNumberOfRequiredParameters();
    foreach ($r->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() : 'none';
        $def = '';
        if ($p->isOptional()) {
            try {
                $def = '='.var_export($p->getDefaultValue(), true);
            } catch (Throwable $e) {
                $def = '=opt';
            }
        }
        echo " |$t \${$p->getName()}$def";
    }
    echo PHP_EOL;
}
$d = date_create_immutable_from_format(format: 'Y-m-d', datetime: '2024-02-29');
echo 'named=', $d instanceof DateTimeImmutable ? 'ok' : 'bad', PHP_EOL;
?>
--EXPECT--
date_create_from_format ret=DateTime|false req=2 |string $format |string $datetime |?DateTimeZone $timezone=NULL
date_create_immutable_from_format ret=DateTimeImmutable|false req=2 |string $format |string $datetime |?DateTimeZone $timezone=NULL
date_modify ret=DateTime|false req=2 |DateTime $object |string $modifier
named=ok
