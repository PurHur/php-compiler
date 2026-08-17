--TEST--
number_format Reflection optional ?string separators (VM, issue #25067, basic_functions.stub.php)
--FILE--
<?php
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
?>
--EXPECT--
number_format(float $num, int $decimals=, ?string $decimal_separator=, ?string $thousands_separator=): string
req=1
decimals_default=0
decimal_separator_default='.'
thousands_separator_default=','
runtime=1,235
named=1 234,50
