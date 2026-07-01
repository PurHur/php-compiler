<?php

declare(strict_types=1);

libxml_use_internal_errors(true);
libxml_clear_errors();

$doc = new DOMDocument();
$ok = $doc->loadXML('<root><unclosed');
if ($ok) {
    fwrite(STDERR, "fail: loadXML should return false\n");
    exit(1);
}

$errors = libxml_get_errors();
if ([] === $errors) {
    fwrite(STDERR, "expected libxml errors after DOM loadXML failure, got 0\n");
    exit(1);
}

$found = false;
foreach ($errors as $error) {
    $msg = $error->message;
    if (str_contains($msg, 'Premature end of data')) {
        echo 'ok message=', $msg, "\n";
        $found = true;
        break;
    }
}
if (!$found) {
    fwrite(STDERR, "fail: no Premature end of data error in buffer\n");
    exit(1);
}
