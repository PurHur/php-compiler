--TEST--
UConverter getAvailable/getAliases/getStandards/get*Type (#20788)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip UConverter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$r = new ReflectionClass('UConverter');
foreach (['getAvailable', 'getAliases', 'getStandards', 'getSourceType', 'getDestinationType'] as $m) {
    echo $m, '=', $r->hasMethod($m) ? '1' : '0', "\n";
}
$avail = UConverter::getAvailable();
echo 'avail_ok=', (int) (\is_array($avail) && count($avail) > 0), "\n";
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
echo 'standards=', (int) (\is_array($std) && count($std) > 0), "\n";
$c = new UConverter('UTF-8', 'ISO-8859-1');
echo 'types=', $c->getSourceType(), ',', $c->getDestinationType(), "\n";
?>
--EXPECT--
getAvailable=1
getAliases=1
getStandards=1
getSourceType=1
getDestinationType=1
avail_ok=1
has_utf8=1
alias_utf8=1
standards=1
types=3,4
