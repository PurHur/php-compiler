--TEST--
NumberFormatter formatCurrency/parseCurrency Reflection/named params match php-src stubs (#23409)
--FILE--
<?php
if (!class_exists('NumberFormatter')) {
    echo "skip\n";
    exit(0);
}
$rm = new ReflectionMethod('NumberFormatter', 'formatCurrency');
echo 'formatCurrency=', implode(',', array_map(static fn ($p) => $p->getName(), $rm->getParameters())), "\n";
$rm = new ReflectionMethod('NumberFormatter', 'parseCurrency');
echo 'parseCurrency=', implode(',', array_map(static fn ($p) => $p->getName(), $rm->getParameters())), "\n";
$rf = new ReflectionFunction('numfmt_format_currency');
echo 'proc_format=', implode(',', array_map(static fn ($p) => $p->getName(), $rf->getParameters())), "\n";
$rf = new ReflectionFunction('numfmt_parse_currency');
echo 'proc_parse=', implode(',', array_map(static fn ($p) => $p->getName(), $rf->getParameters())), "\n";
$nf = new NumberFormatter('en_US', NumberFormatter::CURRENCY);
echo $nf->formatCurrency(amount: 1.5, currency: 'USD'), "\n";
try {
    $nf->formatCurrency(num: 1.5, currency: 'USD');
    echo "legacy-ok\n";
} catch (Throwable $e) {
    echo "legacy-reject\n";
}
$cur = '';
$off = 0;
$parsed = $nf->parseCurrency(string: '$1.50', currency: $cur, offset: $off);
echo 'parsed=', var_export($parsed, true), ' cur=', $cur, ' off=', $off, "\n";
echo "ok\n";
--EXPECT--
formatCurrency=amount,currency
parseCurrency=string,currency,offset
proc_format=formatter,amount,currency
proc_parse=formatter,string,currency,offset
$1.50
legacy-reject
parsed=1.5 cur=USD off=5
ok
