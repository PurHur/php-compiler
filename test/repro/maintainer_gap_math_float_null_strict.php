<?php
declare(strict_types=1);

/**
 * #29782 — float math builtins under strict_types must TypeError on null
 * (php-src ext/standard/math.c Z_PARAM_DOUBLE + zend_verify_arg_type).
 */
error_reporting(E_ALL);

try {
    sin(null);
    echo "bad:sin:no-throw\n";
} catch (TypeError $e) {
    echo "ok:sin:TypeError\n";
}
try {
    cos(null);
    echo "bad:cos:no-throw\n";
} catch (TypeError $e) {
    echo "ok:cos:TypeError\n";
}
try {
    tan(null);
    echo "bad:tan:no-throw\n";
} catch (TypeError $e) {
    echo "ok:tan:TypeError\n";
}
try {
    sqrt(null);
    echo "bad:sqrt:no-throw\n";
} catch (TypeError $e) {
    echo "ok:sqrt:TypeError\n";
}
try {
    log(null);
    echo "bad:log:no-throw\n";
} catch (TypeError $e) {
    echo "ok:log:TypeError\n";
}
try {
    log10(null);
    echo "bad:log10:no-throw\n";
} catch (TypeError $e) {
    echo "ok:log10:TypeError\n";
}
try {
    exp(null);
    echo "bad:exp:no-throw\n";
} catch (TypeError $e) {
    echo "ok:exp:TypeError\n";
}
try {
    expm1(null);
    echo "bad:expm1:no-throw\n";
} catch (TypeError $e) {
    echo "ok:expm1:TypeError\n";
}
try {
    log1p(null);
    echo "bad:log1p:no-throw\n";
} catch (TypeError $e) {
    echo "ok:log1p:TypeError\n";
}
try {
    sinh(null);
    echo "bad:sinh:no-throw\n";
} catch (TypeError $e) {
    echo "ok:sinh:TypeError\n";
}
try {
    cosh(null);
    echo "bad:cosh:no-throw\n";
} catch (TypeError $e) {
    echo "ok:cosh:TypeError\n";
}
try {
    tanh(null);
    echo "bad:tanh:no-throw\n";
} catch (TypeError $e) {
    echo "ok:tanh:TypeError\n";
}
try {
    asinh(null);
    echo "bad:asinh:no-throw\n";
} catch (TypeError $e) {
    echo "ok:asinh:TypeError\n";
}
try {
    acosh(null);
    echo "bad:acosh:no-throw\n";
} catch (TypeError $e) {
    echo "ok:acosh:TypeError\n";
}
try {
    atanh(null);
    echo "bad:atanh:no-throw\n";
} catch (TypeError $e) {
    echo "ok:atanh:TypeError\n";
}
try {
    deg2rad(null);
    echo "bad:deg2rad:no-throw\n";
} catch (TypeError $e) {
    echo "ok:deg2rad:TypeError\n";
}
try {
    rad2deg(null);
    echo "bad:rad2deg:no-throw\n";
} catch (TypeError $e) {
    echo "ok:rad2deg:TypeError\n";
}
try {
    asin(null);
    echo "bad:asin:no-throw\n";
} catch (TypeError $e) {
    echo "ok:asin:TypeError\n";
}
try {
    acos(null);
    echo "bad:acos:no-throw\n";
} catch (TypeError $e) {
    echo "ok:acos:TypeError\n";
}
try {
    atan(null);
    echo "bad:atan:no-throw\n";
} catch (TypeError $e) {
    echo "ok:atan:TypeError\n";
}
try {
    atan2(null, 1.0);
    echo "bad:atan2:no-throw\n";
} catch (TypeError $e) {
    echo "ok:atan2:TypeError\n";
}
try {
    fmod(null, 2.0);
    echo "bad:fmod:no-throw\n";
} catch (TypeError $e) {
    echo "ok:fmod:TypeError\n";
}
try {
    hypot(null, 1.0);
    echo "bad:hypot:no-throw\n";
} catch (TypeError $e) {
    echo "ok:hypot:TypeError\n";
}
try {
    fdiv(null, 2.0);
    echo "bad:fdiv:no-throw\n";
} catch (TypeError $e) {
    echo "ok:fdiv:TypeError\n";
}
