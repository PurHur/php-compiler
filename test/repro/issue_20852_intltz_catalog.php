<?php

declare(strict_types=1);

foreach (['getUnknown', 'getUTC', 'createEnumeration', 'createTimeZoneIDEnumeration', 'getIDForWindowsID', 'getErrorCode', 'getErrorMessage'] as $m) {
    echo $m . '=' . (method_exists('IntlTimeZone', $m) ? 'yes' : 'no') . "\n";
}
echo 'unknown=' . IntlTimeZone::getUnknown()->getID() . "\n";
echo 'utc=' . IntlTimeZone::getUTC()->getID() . "\n";
$ids = iterator_to_array(IntlTimeZone::createEnumeration());
echo 'count=' . count($ids) . "\n";
echo 'est=' . IntlTimeZone::getIDForWindowsID('Eastern Standard Time') . "\n";
