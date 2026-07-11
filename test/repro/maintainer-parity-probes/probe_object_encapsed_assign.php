<?php

class C {
    public function __toString(): string {
        return 'obj';
    }
}

$c = new C();
$s = "prefix{$c}suffix";
var_export($s);
echo "\n";

echo "prefix{$c}suffix\n";
return "prefix{$c}suffix";
