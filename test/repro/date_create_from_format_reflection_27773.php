<?php
/**
 * #27773 — date_create_from_format / immutable_from_format / date_modify Reflection.
 */
foreach (['date_create_from_format', 'date_create_immutable_from_format', 'date_modify'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none';
    echo ' arity=', $r->getNumberOfParameters(), ' req=', $r->getNumberOfRequiredParameters();
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
$d2 = date_create_from_format('Y-m-d', '2024-02-29');
echo 'twoarg=', $d2 instanceof DateTime ? 'ok' : 'bad', PHP_EOL;
