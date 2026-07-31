<?php
/**
 * MessageFormatter spellout/ordinal/duration/selectordinal (#25227).
 * php-src: ext/intl/msgformat/msgformat_format.c / ICU MessageFormat types
 */
$sel = '{0,selectordinal, one{#st} two{#nd} few{#rd} other{#th}}';
$cases = [
    ['{0,spellout}', [42]],
    ['{0,ordinal}', [3]],
    ['{0,duration}', [125]],
    ['{0,duration}', [42]],
    [$sel, [1]],
    [$sel, [2]],
    [$sel, [3]],
    [$sel, [4]],
    [$sel, [11]],
    [$sel, [21]],
    [$sel, [22]],
    ['{0,spellout}', [0]],
    ['{0,ordinal}', [1]],
    ['{0,ordinal}', [11]],
    ['{0,duration}', [3661]],
];
foreach ($cases as [$p, $a]) {
    $fmt = new MessageFormatter('en_US', $p);
    echo $p, ' ', json_encode($a), ' => ', $fmt->format($a), "\n";
}
