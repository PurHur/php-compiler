<?php
declare(strict_types=1);

interface PhpcDnfA {}
interface PhpcDnfB {}
interface PhpcDnfC {}
class PhpcDnfBC implements PhpcDnfB, PhpcDnfC {}

function phpc_dnf_probe(PhpcDnfA|(PhpcDnfB&PhpcDnfC) $arg): string {
    return $arg::class;
}

echo phpc_dnf_probe(new PhpcDnfBC()), "\n";
