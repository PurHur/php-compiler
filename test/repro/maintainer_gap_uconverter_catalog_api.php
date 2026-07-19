<?php

declare(strict_types=1);

/**
 * Repro for #20788 — UConverter catalog / type APIs.
 *
 * php-src: ext/intl/converter/converter.c (ucnv_countAvailable / getAlias / getType)
 */
$r = new ReflectionClass('UConverter');
foreach (['getAvailable', 'getAliases', 'getStandards', 'getSourceType', 'getDestinationType'] as $m) {
    echo $m, '=', $r->hasMethod($m) ? '1' : '0', "\n";
}
$avail = UConverter::getAvailable();
echo 'avail_count=', count($avail), "\n";
echo 'has_utf8=', (int) in_array('UTF-8', $avail, true), "\n";
$aliases = UConverter::getAliases('UTF-8');
$aliasHit = false;
if (\is_array($aliases)) {
    foreach ($aliases as $a) {
        $n = strtoupper(str_replace(['-', '_'], '', (string) $a));
        if ('UTF8' === $n) {
            $aliasHit = true;
            break;
        }
    }
}
echo 'alias_utf8=', (int) $aliasHit, "\n";
$std = UConverter::getStandards();
echo 'standards=', (\is_array($std) && count($std) > 0) ? '1' : '0', "\n";
$c = new UConverter('UTF-8', 'ISO-8859-1');
echo 'srcType=', $c->getSourceType(), ' destType=', $c->getDestinationType(), "\n";
