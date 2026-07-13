<?php
// Issue #18733 — urlencode()/rawurlencode() null must TypeError (re-#18368, ext/standard/url.c).
$failed = 0;
foreach (['urlencode', 'rawurlencode'] as $fn) {
    try {
        $fn(null);
        echo "$fn: FAIL expected TypeError\n";
        ++$failed;
    } catch (TypeError $e) {
        echo "$fn: ok\n";
    }
}
exit($failed > 0 ? 1 : 0);
