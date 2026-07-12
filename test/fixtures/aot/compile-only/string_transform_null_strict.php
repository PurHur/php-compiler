<?php
// Compile-only (#18358): nl2br()/wordwrap()/stripslashes() must lower null TypeError guards for AOT.
foreach (['nl2br', 'wordwrap', 'stripslashes'] as $fn) {
    try {
        $fn(null);
        echo "$fn: NO_THROW\n";
    } catch (TypeError $e) {
        echo $fn.': '.$e->getMessage()."\n";
    }
}
