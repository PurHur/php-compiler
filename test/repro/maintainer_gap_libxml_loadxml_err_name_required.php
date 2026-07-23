<?php
/** #22655 — DOMDocument::loadXML('<') → XML_ERR_NAME_REQUIRED (68), not generic code 4. */
libxml_use_internal_errors(true);
libxml_clear_errors();
$d = new DOMDocument();
$d->loadXML('<');
$e = libxml_get_last_error();
echo ($e ? trim($e->message) : 'none'), '|', ($e ? $e->code : -1), "\n";
