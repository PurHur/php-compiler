<?php
/**
 * Repro #26566 — Dom\ living nodes allow dynamic props with E_DEPRECATED under PROFILE=8.4
 * (Zend php-src 8.4/8.5; corrects over-strict Error from #26055/#26371).
 */
ini_set('error_reporting', (string) E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo 'DEPRECATED:', $msg, "\n";

        return true;
    }

    return false;
});

function tryWrite(object $o, string $label): void
{
    try {
        $o->phpcDyn = 1;
        echo $label, ': wrote isset=', isset($o->phpcDyn) ? '1' : '0', "\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ':', $e->getMessage(), "\n";
    }
}

$legacy = new DOMDocument();
$legacy->loadXML('<r/>');
tryWrite($legacy->documentElement, 'legacy-DOMElement');

$html = Dom\HTMLDocument::createFromString('<p></p>', LIBXML_NOERROR);
tryWrite($html->documentElement, 'Dom\\HTMLElement');

$xml = Dom\XMLDocument::createFromString('<r/>');
tryWrite($xml->documentElement, 'Dom\\Element');
