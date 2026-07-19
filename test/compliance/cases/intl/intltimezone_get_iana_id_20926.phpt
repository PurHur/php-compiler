--TEST--
IntlTimeZone::getIanaID advertisement paired with intltz_get_iana_id (#20926)
--FILE--
<?php
$oop = method_exists('IntlTimeZone', 'getIanaID');
$proc = function_exists('intltz_get_iana_id');
echo 'paired=', ($oop === $proc) ? 'yes' : 'no', "\n";
echo 'state=', $oop ? 'advertised' : 'withheld', "\n";
if ($oop) {
    // Positive path (ICU ≥74 hosts)
    $v = IntlTimeZone::getIanaID('US/Pacific');
    echo 'pacific_ok=', ($v === 'America/Los_Angeles') ? 'yes' : 'no', "\n";
    echo 'proc_ok=', (intltz_get_iana_id('US/Pacific') === $v) ? 'yes' : 'no', "\n";
    echo 'bad_ok=', (false === IntlTimeZone::getIanaID('Not/A/Zone')) ? 'yes' : 'no', "\n";
}
?>
--EXPECTF--
paired=yes
state=%s%A
