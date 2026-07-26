<?php
$cur = session_name();
$session = session_name(name: $cur);
$rs = new ReflectionFunction('session_name');
$sessionParam = null;
foreach ($rs->getParameters() as $p) {
    $sessionParam = $p->getName();
}
$newnameMsg = null;
try {
    session_name(newname: $cur);
    $newnameMsg = 'newname accepted';
} catch (Throwable $e) {
    $newnameMsg = $e->getMessage();
}

$prev = error_reporting(error_level: E_ERROR);
$now = error_reporting();
error_reporting(error_level: $prev);
$rf = new ReflectionFunction('error_reporting');
$errorParam = null;
foreach ($rf->getParameters() as $p) {
    $errorParam = $p->getName();
}
$legacyMsg = null;
try {
    error_reporting(new_error_level: E_ERROR);
    $legacyMsg = 'new_error_level accepted';
} catch (Throwable $e) {
    $legacyMsg = $e->getMessage();
}

echo 'session_roundtrip:', $session === $cur ? '1' : '0', PHP_EOL;
echo 'session_name_param:', $sessionParam, PHP_EOL;
echo $newnameMsg, PHP_EOL;
echo 'error_reporting_set:', (string) $prev, PHP_EOL;
echo 'error_reporting_now:', (string) $now, PHP_EOL;
echo 'error_reporting:', $errorParam, PHP_EOL;
echo $legacyMsg, PHP_EOL;
