--TEST--
Dom\HTMLDocument::createFromString leaves doctype null — no HTML 4.0 Transitional (#26924)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$d = Dom\HTMLDocument::createFromString('<p>x</p>', LIBXML_NOERROR);
echo 'doctype=', $d->doctype ? $d->doctype->name : 'null', "\n";
echo 'save=', var_export($d->saveHtml(), true), "\n";

$d2 = Dom\HTMLDocument::createFromString(
    '<!DOCTYPE html><html><body><p>y</p></body></html>',
    LIBXML_NOERROR
);
echo 'doctype2=', $d2->doctype ? $d2->doctype->name : 'null', "\n";
echo 'public2=', var_export($d2->doctype ? $d2->doctype->publicId : null, true), "\n";
echo 'save2=', var_export($d2->saveHtml(), true), "\n";

$leg = new DOMDocument();
@$leg->loadHTML('<p>z</p>', LIBXML_NOERROR);
echo 'legacy_doctype=', $leg->doctype ? $leg->doctype->name : 'null', "\n";
echo 'legacy_public=', $leg->doctype ? $leg->doctype->publicId : 'n/a', "\n";
?>
--EXPECT--
doctype=null
save='<html><head></head><body><p>x</p></body></html>'
doctype2=html
public2=''
save2='<!DOCTYPE html><html><head></head><body><p>y</p></body></html>'
legacy_doctype=html
legacy_public=-//W3C//DTD HTML 4.0 Transitional//EN
