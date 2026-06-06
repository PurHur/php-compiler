<?php
// Repro for #6967 — never in declared property union must compile-fatal with Zend message.
class C {
    public int|never $x;
}
echo "ok\n";
