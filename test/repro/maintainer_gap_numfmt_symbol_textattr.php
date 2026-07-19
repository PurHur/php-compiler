<?php

declare(strict_types=1);

/**
 * Repro for #20789 — NumberFormatter getSymbol/setSymbol/getTextAttribute/setTextAttribute.
 *
 * php-src: ext/intl/formatter/formatter_attr.c, formatter.stub.php
 */
$r = new ReflectionClass('NumberFormatter');
foreach (['getSymbol', 'setSymbol', 'getTextAttribute', 'setTextAttribute'] as $m) {
    echo $m, '=', $r->hasMethod($m) ? '1' : '0', "\n";
}
$f = NumberFormatter::create('en_US', NumberFormatter::DECIMAL);
echo 'dec=', $f->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL), "\n";
echo 'group=', $f->getSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL), "\n";
$f->setSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL, '*');
echo 'after_set=', $f->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL), "\n";
echo 'neg_prefix=', $f->getTextAttribute(NumberFormatter::NEGATIVE_PREFIX), "\n";
$f->setTextAttribute(NumberFormatter::POSITIVE_PREFIX, 'P:');
echo 'after_text=', $f->getTextAttribute(NumberFormatter::POSITIVE_PREFIX), "\n";
