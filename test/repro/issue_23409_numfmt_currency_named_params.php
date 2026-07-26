<?php
/**
 * Repro #23409 — NumberFormatter::formatCurrency/parseCurrency stub named params.
 * php-src: ext/intl/formatter/formatter.stub.php
 */
if (!class_exists('NumberFormatter')) {
    echo "skip\n";
    exit(0);
}
$rm = new ReflectionMethod('NumberFormatter', 'formatCurrency');
foreach ($rm->getParameters() as $p) {
    echo 'fc=', $p->getName(), "\n";
}
$rm = new ReflectionMethod('NumberFormatter', 'parseCurrency');
foreach ($rm->getParameters() as $p) {
    echo 'pc=', $p->getName(), "\n";
}
$nf = new NumberFormatter('en_US', NumberFormatter::CURRENCY);
try {
    echo $nf->formatCurrency(amount: 1.5, currency: 'USD'), "\n";
} catch (Throwable $e) {
    echo 'err=', $e->getMessage(), "\n";
}
try {
    echo $nf->formatCurrency(num: 1.5, currency: 'USD'), "\n";
} catch (Throwable $e) {
    echo 'legacy=', $e->getMessage(), "\n";
}
