<?php

declare(strict_types=1);

$o = new ArrayObject([1, 2]);
if (2 !== count($o)) {
    echo "direct_fail\n";
    exit(1);
}

$anon = new class extends ArrayObject {
    public function __construct()
    {
        parent::__construct([1, 2]);
    }
};

if (2 !== count($anon)) {
    echo "subclass_fail\n";
    exit(1);
}

echo "arrayobject_subclass_construct_ok\n";
