<?php
// AOT lint: number_format Zend stub optionals + named separators (#25067).
$r = new ReflectionFunction('number_format');
foreach ($r->getParameters() as $p) {
    $p->getName();
    $p->isOptional();
    $p->hasType() ? (string) $p->getType() : '';
}
number_format(1234.5);
number_format(num: 1234.5, decimals: 2, decimal_separator: ',', thousands_separator: ' ');
