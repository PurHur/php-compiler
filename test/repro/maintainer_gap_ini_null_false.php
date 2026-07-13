<?php

$cases = [
    'ini_get(null)' => static fn () => ini_get(null),
    'ini_set(null, "1")' => static fn () => ini_set(null, '1'),
    'get_cfg_var(null)' => static fn () => get_cfg_var(null),
];

foreach ($cases as $label => $call) {
    if (false !== $call()) {
        fwrite(STDERR, "$label: expected false\n");
        exit(1);
    }
    echo "$label: ok\n";
}
