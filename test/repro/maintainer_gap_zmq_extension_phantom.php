<?php

declare(strict_types=1);

/**
 * #23964 — ext/zmq must not phantom when host Zend lacks pecl-zmq.
 */
if (extension_loaded('zmq')) {
    fwrite(STDERR, "skip host ext/zmq loaded\n");
    exit(0);
}
$loaded = extension_loaded('zmq');
$ctx = class_exists('ZMQContext', false);
$zmq = class_exists('ZMQ', false);
$fn = function_exists('zmq_context');
if ($loaded || $ctx || $zmq || $fn) {
    fwrite(STDERR, 'FAIL: extension_loaded(zmq)='.var_export($loaded, true)
        .' class_exists(ZMQContext)='.var_export($ctx, true)
        .' class_exists(ZMQ)='.var_export($zmq, true)
        .' function_exists(zmq_context)='.var_export($fn, true)."\n");
    exit(1);
}
echo "ok\n";
