<?php

function t(): void {
    parse_str('a=1&b=2');
    var_dump(isset($a), $a ?? null, isset($b), $b ?? null);
}

t();
