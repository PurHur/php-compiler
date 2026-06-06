<?php
// Maintainer repro for #7052 — standalone never property must compile-fatal.
class C {
    public never $p;
}
echo "ok\n";
