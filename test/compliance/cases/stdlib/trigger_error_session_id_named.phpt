--TEST--
stdlib trigger_error/session_id Zend stub named params (#23402)
--FILE--
<?php
@trigger_error(message: 'x', error_level: E_USER_NOTICE);
echo "te_ok\n";
$rf = new ReflectionFunction('trigger_error');
$teNames = [];
foreach ($rf->getParameters() as $p) {
    $teNames[] = $p->getName();
}
echo 'te_params:', implode(',', $teNames), "\n";
$legacyTe = null;
try {
    @trigger_error(message: 'x', error_type: E_USER_NOTICE);
    $legacyTe = 'error_type accepted';
} catch (Throwable $e) {
    $legacyTe = $e->getMessage();
}
echo $legacyTe, "\n";

$prev = session_id();
$seed = ($prev === false || $prev === '') ? 'abc123sess01' : $prev;
$round = session_id(id: $seed);
echo 'sid_ok:', (is_string($round) || $round === false) ? '1' : '0', "\n";
$rs = new ReflectionFunction('session_id');
$sidNames = [];
foreach ($rs->getParameters() as $p) {
    $sidNames[] = $p->getName();
}
echo 'sid_params:', implode(',', $sidNames), "\n";
$legacySid = null;
try {
    session_id(newid: 'x');
    $legacySid = 'newid accepted';
} catch (Throwable $e) {
    $legacySid = $e->getMessage();
}
echo $legacySid, "\n";
?>
--EXPECT--
te_ok
te_params:message,error_level
Unknown named parameter $error_type
sid_ok:1
sid_params:id
Unknown named parameter $newid
