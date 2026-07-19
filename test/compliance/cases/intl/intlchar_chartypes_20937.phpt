--TEST--
IntlChar::enumCharTypes() contiguous general-category ranges (#20937)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlChar withheld until extension_loaded(\'intl\') (#19670/#20937)';
}
?>
--FILE--
<?php
echo 'enumCharTypes=', method_exists('IntlChar', 'enumCharTypes') ? 'yes' : 'no', "\n";
$n = 0;
$first = [];
$digit = null;
$upper = null;
IntlChar::enumCharTypes(function ($start, $limit, $type) use (&$n, &$first, &$digit, &$upper) {
    if ($n < 5) {
        $first[] = $start.'-'.$limit.':'.$type;
    }
    if (null === $digit && $start <= 0x30 && $limit > 0x30) {
        $digit = $start.'-'.$limit.':'.$type;
    }
    if (null === $upper && $start <= 0x41 && $limit > 0x41) {
        $upper = $start.'-'.$limit.':'.$type;
    }
    ++$n;
});
echo 'calls=', $n >= 100 ? 'many' : (string) $n, "\n";
echo 'first=', implode('|', $first), "\n";
echo 'digit=', $digit, "\n";
echo 'upper=', $upper, "\n";
echo 'type0=', IntlChar::charType(0), "\n";
echo 'typeA=', IntlChar::charType('A'), "\n";
echo 'type0d=', IntlChar::charType('0'), "\n";
?>
--EXPECT--
enumCharTypes=yes
calls=many
first=0-32:15|32-33:12|33-36:23|36-37:25|37-40:23
digit=48-58:9
upper=65-91:1
type0=15
typeA=1
type0d=9
