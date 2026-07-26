<?php
// Issue #23339 — isset/empty on hooked property with null distinct backing (re-#17260).
class C {
    private ?string $_n = null;
    public string $name {
        get => $this->_n ?? 'anon';
        set(?string $v) => $this->_n = $v;
    }
}
$c = new C();
echo 'isset=', isset($c->name) ? '1' : '0', "\n";
echo 'empty=', empty($c->name) ? '1' : '0', "\n";
