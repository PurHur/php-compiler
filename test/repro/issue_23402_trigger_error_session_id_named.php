<?php
// Issue #23402 — trigger_error/session_id Zend stub named params.
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
$round = session_id(id: $prev === false || $prev === '' ? 'abc123sess01' : $prev);
echo 'sid_ok:', is_string($round) || $round === false ? '1' : '0', "\n";
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
