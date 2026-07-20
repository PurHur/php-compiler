--TEST--
IntlTimeZone::getIanaID / intltz_get_iana_id Reflection arginfo (#21553)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$oop = method_exists('IntlTimeZone', 'getIanaID');
$proc = function_exists('intltz_get_iana_id');
if ($oop !== $proc) {
    echo "ok=fail_pair\n";
    return;
}
if (!$oop) {
    echo "ok=withheld\n";
    return;
}
$m = new ReflectionMethod('IntlTimeZone', 'getIanaID');
$names = array_map(static fn ($p) => $p->getName(), $m->getParameters());
$t = $m->getParameters()[0]->getType() ?? null;
$fn = new ReflectionFunction('intltz_get_iana_id');
$fnNames = array_map(static fn ($p) => $p->getName(), $fn->getParameters());
$ok = 1 === $m->getNumberOfParameters()
    && 1 === $m->getNumberOfRequiredParameters()
    && $m->isStatic()
    && ['zoneId'] === $names
    && null !== $t
    && 'string' === (string) $t
    && is_string(IntlTimeZone::getIanaID('Europe/Kiev'))
    && 1 === $fn->getNumberOfParameters()
    && ['zoneId'] === $fnNames;
echo $ok ? "ok=pass\n" : "ok=fail\n";
?>
--EXPECTF--
ok=%s
