<?php
/** Repro #19296 — HTML escape/unescape null TypeError on PHP_COMPILER_PROFILE=8.4. */
$cases = [
    'htmlspecialchars' => static fn () => htmlspecialchars(null),
    'htmlentities' => static fn () => htmlentities(null),
    'htmlspecialchars_decode' => static fn () => htmlspecialchars_decode(null),
    'html_entity_decode' => static fn () => html_entity_decode(null),
];
foreach ($cases as $name => $fn) {
    try {
        var_export($fn());
        echo " $name:OK\n";
    } catch (Throwable $e) {
        echo $name.': '.get_class($e)."\n";
    }
}
