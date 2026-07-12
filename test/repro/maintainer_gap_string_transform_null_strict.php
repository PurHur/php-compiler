<?php
// Repro #18358: nl2br()/wordwrap()/stripslashes() null operand must TypeError (php-src 8.2+).
foreach (['nl2br', 'wordwrap', 'stripslashes'] as $fn) {
    try {
        $result = $fn(null);
        echo $fn.'=str:'.var_export($result, true)."\n";
    } catch (TypeError $e) {
        echo $fn.': '.$e->getMessage()."\n";
    }
}
