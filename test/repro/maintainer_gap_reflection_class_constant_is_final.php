<?php

interface I {
    final const X = 1;
}

class C implements I {
}

$rc = new ReflectionClassConstant(C::class, 'X');
if ($rc->isFinal()) {
    echo "ok\n";
} else {
    echo "fail: isFinal false\n";
}

$rcDirect = new ReflectionClassConstant(I::class, 'X');
if ($rcDirect->isFinal()) {
    echo "ok\n";
} else {
    echo "fail: interface direct isFinal false\n";
}

class D {
    const Y = 2;
}
$rcNonFinal = new ReflectionClassConstant(D::class, 'Y');
if (!$rcNonFinal->isFinal()) {
    echo "ok\n";
} else {
    echo "fail: non-final reports true\n";
}
