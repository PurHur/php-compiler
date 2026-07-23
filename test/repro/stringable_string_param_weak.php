<?php
// Issue #22548 — weak userland string params coerce Stringable via __toString
class S implements Stringable {
    public function __toString(): string { return 'S'; }
}
function f(string $x) { echo $x; }
f(new S());
echo " ok\n";
