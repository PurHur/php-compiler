<?php
// Zend stub: number_format(float $num, int $decimals = 0, ?string $decimal_separator = ".", ?string $thousands_separator = ","): string
$r = new ReflectionFunction('number_format');
$ps = [];
foreach ($r->getParameters() as $p) {
    $t = $p->hasType() ? (string) $p->getType() : '';
    $opt = $p->isOptional() ? '=' : '';
    $ps[] = trim($t.' $'.$p->getName().$opt);
}
$ret = $r->hasReturnType() ? (string) $r->getReturnType() : 'untyped';
echo $r->getName().'('.implode(', ', $ps).'): '.$ret, PHP_EOL;
echo 'req='.$r->getNumberOfRequiredParameters(), PHP_EOL;
foreach ($r->getParameters() as $p) {
    if (!$p->isOptional()) {
        continue;
    }
    echo $p->getName().'_default=', $p->isDefaultValueAvailable()
        ? var_export($p->getDefaultValue(), true)
        : 'NONE', PHP_EOL;
}
echo 'runtime=', number_format(1234.5), PHP_EOL;
echo 'named=', number_format(num: 1234.5, decimals: 2, decimal_separator: ',', thousands_separator: ' '), PHP_EOL;
