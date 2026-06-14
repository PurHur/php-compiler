<?php
declare(strict_types=1);

class D {
    private int $secret = 99;
    protected int $prot = 1;
    public int $pub = 2;
}
var_export(new D());
echo "\n";
