<?php
// Maintainer repro for #26518 — standalone void property must compile-fatal.
class C {
    public void $p;
}
echo "ok\n";
