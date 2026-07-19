<?php
// Guard #20007/#21181 — strlen/case/rev/bin2hex soft-null DEP+coerce on PROFILE=8.4
$cases = [
    'strlen' => static fn () => strlen(null),
    'strtolower' => static fn () => strtolower(null),
    'strtoupper' => static fn () => strtoupper(null),
    'strrev' => static fn () => strrev(null),
    'bin2hex' => static fn () => bin2hex(null),
];
foreach ($cases as $label => $factory) {
    try {
        $r = $factory();
        echo "$label: uncaught ", var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
