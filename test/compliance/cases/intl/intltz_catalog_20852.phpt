--TEST--
IntlTimeZone getUnknown/getUTC/createEnumeration/getIDForWindowsID (#20852)
--FILE--
<?php
foreach (['getUnknown','getUTC','createEnumeration','createTimeZoneIDEnumeration','getIDForWindowsID','getErrorCode','getErrorMessage'] as $m) {
    echo $m, '=', method_exists('IntlTimeZone', $m) ? 'yes' : 'no', "\n";
}

$u = IntlTimeZone::getUnknown();
echo 'unknown=', $u->getID(), "\n";
$utc = IntlTimeZone::getUTC();
echo 'utc=', $utc->getID(), "\n";

$enum = IntlTimeZone::createEnumeration();
echo 'enum_iter=', (int) ($enum instanceof IntlIterator), "\n";
$ids = iterator_to_array($enum);
echo 'enum_count_gt100=', (int) (count($ids) > 100), "\n";
echo 'enum_has_utc=', (int) in_array('UTC', $ids, true), "\n";

$ny = IntlTimeZone::getIDForWindowsID('Eastern Standard Time');
echo 'windows_est=', $ny, "\n";

echo 'err=', $utc->getErrorCode(), "\n";
echo 'errmsg=', $utc->getErrorMessage(), "\n";
?>
--EXPECT--
getUnknown=yes
getUTC=yes
createEnumeration=yes
createTimeZoneIDEnumeration=yes
getIDForWindowsID=yes
getErrorCode=yes
getErrorMessage=yes
unknown=Etc/Unknown
utc=UTC
enum_iter=1
enum_count_gt100=1
enum_has_utc=1
windows_est=America/New_York
err=0
errmsg=U_ZERO_ERROR
