<?php

declare(strict_types=1);

// Regression: LibXMLError::$message empty when passed inline to trim()/strlen() (#14467 follow-up).
libxml_use_internal_errors(true);
libxml_clear_errors();
$d = new DOMDocument();
@$d->loadXML('<bad>');
$e = libxml_get_errors()[0];
if ($e->code !== 77) {
    fwrite(STDERR, 'expected code 77, got '.$e->code."\n");
    exit(1);
}
$inline = trim($e->message);
if ($inline !== 'Premature end of data in tag bad line 1') {
    fwrite(STDERR, 'inline trim mismatch: '.var_export($inline, true)."\n");
    exit(1);
}
echo "ok\n";
