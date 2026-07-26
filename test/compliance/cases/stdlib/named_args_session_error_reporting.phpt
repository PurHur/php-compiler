--TEST--
error_reporting/session_name Zend stub named params (VM, issue #23436)
--FILE--
<?php
$cur = session_name();
$session = session_name(name: $cur);
$rs = new ReflectionFunction('session_name');
$snames = [];
foreach ($rs->getParameters() as $p) {
    $snames[] = $p->getName();
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
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
$legacyMsg = null;
try {
    error_reporting(new_error_level: E_ERROR);
    $legacyMsg = 'new_error_level accepted';
} catch (Throwable $e) {
    $legacyMsg = $e->getMessage();
}

echo 'session_roundtrip:', $session === $cur ? '1' : '0', PHP_EOL;
echo 'session_name:', implode(',', $snames), PHP_EOL;
echo $newnameMsg, PHP_EOL;
echo 'er_set_ok:', is_int($prev) ? '1' : '0', PHP_EOL;
echo 'er_now:', (string) $now, PHP_EOL;
echo 'error_reporting:', implode(',', $names), PHP_EOL;
echo $legacyMsg, PHP_EOL;
--EXPECT--
session_roundtrip:1
session_name:name
Unknown named parameter $newname
er_set_ok:1
er_now:1
error_reporting:error_level
Unknown named parameter $new_error_level
