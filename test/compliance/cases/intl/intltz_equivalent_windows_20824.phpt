--TEST--
IntlTimeZone countEquivalentIDs/getEquivalentID/getTZDataVersion/getWindowsID (#20824)
--FILE--
<?php
foreach (['countEquivalentIDs','getEquivalentID','getTZDataVersion','getWindowsID'] as $m) {
    echo $m, '=', method_exists('IntlTimeZone', $m) ? 'yes' : 'no', "\n";
}

echo 'count_ny=', IntlTimeZone::countEquivalentIDs('America/New_York'), "\n";
echo 'eq0=', IntlTimeZone::getEquivalentID('America/New_York', 0), "\n";
echo 'eq1=', IntlTimeZone::getEquivalentID('America/New_York', 1), "\n";
echo 'eq_oob=', var_export(IntlTimeZone::getEquivalentID('America/New_York', 99), true), "\n";
echo 'count_bad=', IntlTimeZone::countEquivalentIDs('Not/A/Zone'), "\n";

$ver = IntlTimeZone::getTZDataVersion();
echo 'ver_shape=', (int) (is_string($ver) && preg_match('/^\d{4}[a-z]$/', $ver) === 1), "\n";

echo 'win_ny=', IntlTimeZone::getWindowsID('America/New_York'), "\n";
echo 'win_us=', IntlTimeZone::getWindowsID('US/Eastern'), "\n";
echo 'win_tokyo=', IntlTimeZone::getWindowsID('Asia/Tokyo'), "\n";
echo 'round=', IntlTimeZone::getIDForWindowsID('Eastern Standard Time'), "\n";
echo 'win_bad=', var_export(IntlTimeZone::getWindowsID('Not/A/Zone'), true), "\n";
?>
--EXPECT--
countEquivalentIDs=yes
getEquivalentID=yes
getTZDataVersion=yes
getWindowsID=yes
count_ny=2
eq0=America/New_York
eq1=US/Eastern
eq_oob=''
count_bad=0
ver_shape=1
win_ny=Eastern Standard Time
win_us=Eastern Standard Time
win_tokyo=Tokyo Standard Time
round=America/New_York
win_bad=false
