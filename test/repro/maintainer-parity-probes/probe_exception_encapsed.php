<?php
class C {
    public function __toString(): string {
        return 'obj';
    }
}
$c = new C();
$e = new Exception("err: {$c}");
echo $e->getMessage();
echo "\n";
