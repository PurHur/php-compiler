--TEST--
IntlTimeZone getUnknown/getGMT/createEnumeration/getIDForWindowsID (#20852; getUTC withheld #26745)
--FILE--
<?php
foreach (['getUnknown','getGMT','getUTC','createEnumeration','createTimeZoneIDEnumeration','getIDForWindowsID','getErrorCode','getErrorMessage'] as $m) {
    echo $m, '=', method_exists('IntlTimeZone', $m) ? 'yes' : 'no', "\n";
}

$u = IntlTimeZone::getUnknown();
echo 'unknown=', $u->getID(), "\n";
// php-src has getGMT (not getUTC) for the GMT/UTC zone (#26745)
$gmt = IntlTimeZone::getGMT();
echo 'gmt=', $gmt->getID(), "\n";

$enum = IntlTimeZone::createEnumeration();
echo 'enum_iter=', (int) ($enum instanceof IntlIterator), "\n";
$ids = iterator_to_array($enum);
echo 'enum_count_gt100=', (int) (count($ids) > 100), "\n";
echo 'enum_has_utc=', (int) in_array('UTC', $ids, true), "\n";

$ny = IntlTimeZone::getIDForWindowsID('Eastern Standard Time');
echo 'windows_est=', $ny, "\n";

echo 'err=', $gmt->getErrorCode(), "\n";
echo 'errmsg=', $gmt->getErrorMessage(), "\n";
?>
--EXPECT--
getUnknown=yes
getGMT=yes
getUTC=no
createEnumeration=yes
createTimeZoneIDEnumeration=yes
getIDForWindowsID=yes
getErrorCode=yes
getErrorMessage=yes
unknown=Etc/Unknown
gmt=GMT
enum_iter=1
enum_count_gt100=1
enum_has_utc=1
windows_est=America/New_York
err=0
errmsg=U_ZERO_ERROR
