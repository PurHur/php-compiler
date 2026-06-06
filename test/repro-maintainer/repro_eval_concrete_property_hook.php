<?php
// Maintainer repro for #7031 — concrete property hooks in eval() must compile and run.
$code = <<<'EVAL'
class Evaled {
    public string $name {
        get => strtoupper($this->name ?? "");
        set => $this->name = strtolower($value);
    }
    private string $name = "x";
}
EVAL;
$ok = eval($code);
if ($ok === false) {
    echo "eval-failed\n";
    exit(1);
}
if (!class_exists('Evaled', false)) {
    echo "class-missing\n";
    exit(1);
}
$o = new Evaled();
$o->name = 'AbC';
echo $o->name, "\n";
