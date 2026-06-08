<?php

class C {
    public const VERSION = '1.0';
    private const SECRET = 'hidden';
}

$rc = new ReflectionClass(C::class);
foreach (['getConstants', 'getConstant'] as $m) {
    echo $m, ': ', method_exists($rc, $m) ? 'yes' : 'missing', "\n";
}
if (method_exists($rc, 'getConstants')) {
    var_export($rc->getConstants());
    echo "\n";
}
if (method_exists($rc, 'getConstant')) {
    echo 'VERSION=', $rc->getConstant('VERSION'), "\n";
}
