<?php
/** Issue #22770 — SoapClient non-WSDL: null literal + options array literal. */
try {
    $c = new SoapClient(null, [
        'location' => 'http://127.0.0.1/',
        'uri' => 'http://test/',
        'exceptions' => true,
    ]);
    echo 'ok class=', get_class($c), "\n";
} catch (TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
