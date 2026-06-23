<?php

class ParityForwardStaticProbe {
    public static function f(): int { return 1; }
}
var_export(forward_static_call_array([ParityForwardStaticProbe::class, 'f'], []));
