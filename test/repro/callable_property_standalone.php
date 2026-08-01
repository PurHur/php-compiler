<?php
// Maintainer repro for #26516 — standalone callable property must compile-fatal.
class C {
    public callable $c;
}
echo "ok\n";
