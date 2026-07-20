<?php
// Repro #21351 — htmlspecialchars/htmlentities/nl2br/addslashes TypeError under PROFILE=8.4
$failed = 0;
foreach (['htmlspecialchars', 'htmlentities', 'addslashes', 'nl2br'] as $f) {
    try {
        $f(null);
        echo "$f: FAIL expected TypeError\n";
        ++$failed;
    } catch (TypeError $e) {
        echo "$f: ok\n";
    }
}
foreach (['stripslashes', 'quotemeta'] as $f) {
    try {
        $r = $f(null);
        echo "$f: soft-null ok\n";
    } catch (TypeError $e) {
        echo "$f: unexpected TypeError\n";
        ++$failed;
    }
}
exit($failed > 0 ? 1 : 0);
