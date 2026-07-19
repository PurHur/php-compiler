<?php

declare(strict_types=1);

foreach (['countEquivalentIDs', 'getEquivalentID', 'getTZDataVersion', 'getWindowsID'] as $m) {
    echo $m . '=' . (method_exists('IntlTimeZone', $m) ? 'yes' : 'no') . "\n";
}
echo 'count_ny=' . IntlTimeZone::countEquivalentIDs('America/New_York') . "\n";
echo 'eq0=' . IntlTimeZone::getEquivalentID('America/New_York', 0) . "\n";
echo 'eq1=' . IntlTimeZone::getEquivalentID('America/New_York', 1) . "\n";
echo 'ver_len=' . strlen(IntlTimeZone::getTZDataVersion()) . "\n";
echo 'win_ny=' . IntlTimeZone::getWindowsID('America/New_York') . "\n";
echo 'win_us=' . IntlTimeZone::getWindowsID('US/Eastern') . "\n";
echo 'round=' . IntlTimeZone::getIDForWindowsID(IntlTimeZone::getWindowsID('America/New_York')) . "\n";
