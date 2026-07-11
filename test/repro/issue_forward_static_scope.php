<?php

class ParityForwardStaticScope {
    public static function m(): int { return 3; }
}

try {
    $r = forward_static_call([ParityForwardStaticScope::class, 'm']);
    echo "unexpected_ok:$r\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
