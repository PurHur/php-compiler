<?php
/** Repro for #20209 — gettext/_/dgettext(null msgid) TypeError under PROFILE=8.4. */
foreach (['gettext', '_'] as $f) {
    try {
        $r = $f(null);
        echo "$f COERCED ", var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo "$f ", get_class($e), "\n";
    }
}
try {
    $r = dgettext('messages', null);
    echo 'dgettext COERCED ', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo 'dgettext ', get_class($e), "\n";
}
