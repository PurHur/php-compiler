--TEST--
DNF union RHS intersection parameter type A|(B&C) compiles and runs (#11745)
--FILE--
<?php
interface PhpcDnfA {}
interface PhpcDnfB {}
interface PhpcDnfC {}
class PhpcDnfBC implements PhpcDnfB, PhpcDnfC {}

function phpc_dnf_probe(PhpcDnfA|(PhpcDnfB&PhpcDnfC) $arg): string {
    return $arg::class;
}

echo phpc_dnf_probe(new PhpcDnfBC());
echo "\n";
try {
    phpc_dnf_probe(new stdClass());
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
?>
--EXPECT--
PhpcDnfBC
TypeError
