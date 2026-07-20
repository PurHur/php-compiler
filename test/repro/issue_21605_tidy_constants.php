<?php
foreach ([
    'TIDY_NODETYPE_ROOT' => 0,
    'TIDY_NODETYPE_TEXT' => 4,
    'TIDY_TAG_A' => 1,
    'TIDY_TAG_BODY' => 16,
    'TIDY_TAG_HTML' => 48,
    'TIDY_TAG_VIDEO' => 151,
] as $name => $want) {
    $have = defined($name) ? constant($name) : 'UNDEF';
    echo $name, '=', $have, ($have === $want ? ' ok' : ' FAIL want='.$want), "\n";
}
$n = 0;
foreach (get_defined_constants(false) as $k => $_) {
    if (strncmp($k, 'TIDY_', 5) === 0) {
        ++$n;
    }
}
echo 'count=', $n, ($n >= 160 ? ' ok' : ' FAIL'), "\n";
