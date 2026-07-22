<?php
// Issue #22308 — hook-less final property must fatal on reference profile (Zend 8.2).
class C {
    public final string $x = 'a';
}
echo "parsed_ok\n";
