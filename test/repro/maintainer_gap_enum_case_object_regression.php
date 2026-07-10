<?php
/**
 * Issue #17743 — enum case constant fetch must yield canonical singleton with name/value.
 */
enum E: string {
    case A = 'a';
}

echo E::A->name, "\n";
echo match (E::A) { E::A => 'ok' }, "\n";
echo (E::A === E::cases()[0]) ? 'eq' : 'ne', "\n";
echo var_export(E::A, true), "\n";
