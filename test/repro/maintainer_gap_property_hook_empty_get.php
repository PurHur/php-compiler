<?php
class C {
    public int $x {
        get { echo "GET\n"; return $this->x; }
        set => $this->x = $value;
    }
    private int $x = 0;
}
$c = new C();
ob_start();
$empty = empty($c->x);
$hookOutput = ob_get_clean();
if ('GET' . "\n" !== $hookOutput) {
    echo "fail: empty() must invoke get hook, hook output was: ", var_export($hookOutput, true), "\n";
    exit(1);
}
if (!$empty) {
    echo "fail: empty() on zero backing via get hook must be true\n";
    exit(1);
}

class D {
    public string $y {
        get { echo "GET2\n"; return $this->y; }
        set => $this->y = $value;
    }
    private string $y = 'hi';
}
$d = new D();
ob_start();
$empty2 = empty($d->y);
$hookOutput2 = ob_get_clean();
if ('GET2' . "\n" !== $hookOutput2) {
    echo "fail: empty() must invoke get hook (non-empty), hook output was: ", var_export($hookOutput2, true), "\n";
    exit(1);
}
if ($empty2) {
    echo "fail: empty() on non-empty string via get hook must be false\n";
    exit(1);
}

echo "ok\n";
