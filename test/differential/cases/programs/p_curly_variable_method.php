<?php
// #36380 reduction: Parsedown uses $this->{"inline$Type"}($x) — Zend runs; AOT must match.
class C {
    public function inlineLink($x) { return "L:$x"; }
    public function go($t) {
        return $this->{"inline$t"}("z");
    }
}
echo (new C())->go("Link"), "\n";
