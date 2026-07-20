--TEST--
stdlib SOAP_ACTOR_* constants (#21621, ext/soap/php_soap.h)
--FILE--
<?php
$expect = [
    'SOAP_ACTOR_NEXT' => 1,
    'SOAP_ACTOR_NONE' => 2,
    'SOAP_ACTOR_UNLIMATERECEIVER' => 3,
];
$ok = 1;
foreach ($expect as $name => $val) {
    if (!defined($name) || constant($name) !== $val) {
        $ok = 0;
        echo 'bad=', $name, ' got=', defined($name) ? (string) constant($name) : 'MISSING', "\n";
    }
}
if (defined('SOAP_ACTOR_UNLIMATED')) {
    $ok = 0;
    echo "bad=SOAP_ACTOR_UNLIMATED still defined\n";
}
echo 'ok=', $ok, "\n";
echo 'ultimate=', SOAP_ACTOR_UNLIMATERECEIVER, "\n";
?>
--EXPECT--
ok=1
ultimate=3
