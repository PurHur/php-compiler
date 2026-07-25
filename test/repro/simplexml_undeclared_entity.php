<?php
/**
 * Repro #22775 — SimpleXMLElement undeclared entity must fail like Zend/libxml.
 */
libxml_use_internal_errors(true);
libxml_clear_errors();

try {
    $x = new SimpleXMLElement('<r>&foo;</r>');
    echo 'OK asXML=', var_export($x->asXML(), true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$errors = libxml_get_errors();
echo 'errors=', count($errors), "\n";
if (isset($errors[0])) {
    echo 'code=', $errors[0]->code, "\n";
    echo 'level=', $errors[0]->level, "\n";
    echo 'msg=', json_encode(trim($errors[0]->message)), "\n";
    echo 'col=', $errors[0]->column, "\n";
}

libxml_clear_errors();
$loaded = @simplexml_load_string('<r>&bar;</r>');
echo 'load=', var_export($loaded, true), "\n";
$e2 = libxml_get_errors();
echo 'load_errors=', count($e2), "\n";
if (isset($e2[0])) {
    echo 'load_code=', $e2[0]->code, "\n";
    echo 'load_msg=', json_encode(trim($e2[0]->message)), "\n";
}
