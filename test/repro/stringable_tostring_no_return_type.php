<?php
// Issue #25727 — Zend allows untyped public __toString with implements Stringable.
class S implements Stringable {
    public function __toString() {
        return "hi";
    }
}
echo (string) (new S()), "\n";
