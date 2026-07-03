<?php

declare(strict_types=1);

$d = new DOMDocument();
$xml = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd"><html/>';
if (!$d->loadXML($xml)) {
    exit(1);
}
if (null === $d->doctype) {
    exit(1);
}
if ($d->doctype->name !== 'html') {
    exit(1);
}
if ($d->doctype->publicId !== '-//W3C//DTD XHTML 1.0 Strict//EN') {
    exit(1);
}
echo "ok\n";
