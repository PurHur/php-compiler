<?php

declare(strict_types=1);

if (!class_exists('ReflectionExtension')) {
    echo "fail: ReflectionExtension class missing\n";
    exit(1);
}

$rc = new ReflectionClass(stdClass::class);
if (!method_exists($rc, 'getExtension')) {
    echo "fail: ReflectionClass::getExtension missing\n";
    exit(1);
}

$ext = $rc->getExtension();
if (!$ext instanceof ReflectionExtension) {
    echo 'fail: getExtension returned '.get_debug_type($ext)."\n";
    exit(1);
}
if ('Core' !== $ext->getName()) {
    echo 'fail: extension name='.$ext->getName()."\n";
    exit(1);
}

echo "ok\n";
