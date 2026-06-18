<?php
class C {
    public string $x {
        get { return 'hooked'; }
    }
    public int $y = 42;
}
$c = new C();
foreach ($c as $k => $v) {
    echo "$k=$v\n";
}
