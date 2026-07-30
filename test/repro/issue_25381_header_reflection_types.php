<?php
// #25381 — header_remove / headers_sent / header_register_callback / stream_context_get_default Reflection vs Zend.

// Runtime TypeErrors before any output (ZEND_PARSE_PARAMETERS before headers_sent).
header_remove(null);
$ctx = stream_context_get_default(null);
$ctxOk = get_resource_type($ctx);
try {
    stream_context_get_default(1);
    $intErr = 'int_should_throw';
} catch (TypeError $e) {
    $intErr = $e->getMessage();
}
try {
    header_remove([]);
    $arrErr = 'arr_should_throw';
} catch (TypeError $e) {
    $arrErr = $e->getMessage();
}

$p = (new ReflectionFunction('header_remove'))->getParameters()[0];
echo 'header_remove|', $p->hasType() ? (string) $p->getType() : 'NONE', '|opt=', (int) $p->isOptional(), "\n";

foreach ((new ReflectionFunction('headers_sent'))->getParameters() as $p) {
    echo 'headers_sent|', $p->getName(), '|', $p->hasType() ? (string) $p->getType() : 'NONE', '|opt=', (int) $p->isOptional(), "\n";
}

$p = (new ReflectionFunction('header_register_callback'))->getParameters()[0];
echo 'header_register_callback|', $p->hasType() ? (string) $p->getType() : 'NONE', "\n";

$p = (new ReflectionFunction('stream_context_get_default'))->getParameters()[0];
echo 'stream_context_get_default|', $p->hasType() ? (string) $p->getType() : 'NONE', '|opt=', (int) $p->isOptional(), "\n";

echo 'null_ok|', $ctxOk, "\n";
echo 'int_err|', $intErr, "\n";
echo 'arr_err|', $arrErr, "\n";
