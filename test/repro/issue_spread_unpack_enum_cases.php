<?php
/**
 * Repro for #5568 — argument unpacking must pass enum case objects, not backing scalars.
 * Zend reference: Zend/zend_execute.c (ZEND_SEND_UNPACK).
 */
enum E: int {
    case A = 1;
    case B = 2;
}

function names(...$args): void
{
    foreach ($args as $case) {
        if (!$case instanceof E) {
            echo "not-enum\n";
            continue;
        }
        echo $case->name, "\n";
    }
}

names(...E::cases());
names(...[E::A, E::B]);
