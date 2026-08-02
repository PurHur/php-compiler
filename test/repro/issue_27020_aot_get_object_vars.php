<?php
// Repro #27020 — AOT json_encode(get_object_vars(...)) must not segfault.
class C
{
    public $a = 1;
    private $b = 2;
}
echo json_encode(get_object_vars(new C)), PHP_EOL;
