<?php
// Issue #9625 — unset hook body must not re-enter unset hook (OOM regression).
class C {
    public string $name {
        get => $this->name;
        unset { unset($this->name); }
    }
    private string $name = 'a';
}
$c = new C;
unset($c->name);
echo "ok\n";
