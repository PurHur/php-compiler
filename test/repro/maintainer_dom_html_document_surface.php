<?php
declare(strict_types=1);
/**
 * Maintainer repro: Dom\HTMLDocument living surface (#19580 / #6506).
 *
 * Zend 8.4+: body/title/querySelector/getElementById/createFromFile/saveHtml.
 */
$html = '<!DOCTYPE html><html><head><title>T</title></head><body><div id="x"><span>s</span></div></body></html>';
$d = Dom\HTMLDocument::createFromString($html);
$body = $d->body;
echo 'body=', ($body !== null ? $body->nodeName : 'NULL'), "\n";
echo 'title=', $d->title, "\n";
echo 'qs=', method_exists($d, 'querySelector') ? 'yes' : 'no', "\n";
echo 'gid=', method_exists($d, 'getElementById') ? 'yes' : 'no', "\n";
echo 'cff=', method_exists(Dom\HTMLDocument::class, 'createFromFile') ? 'yes' : 'no', "\n";
echo 'sh=', method_exists($d, 'saveHtml') ? 'yes' : 'no', "\n";
$span = $d->querySelector('span');
echo 'span=', ($span !== null ? $span->textContent : 'NULL'), "\n";
$byId = $d->getElementById('x');
echo 'id=', ($byId !== null ? $byId->nodeName : 'NULL'), "\n";
$saved = $d->saveHtml();
echo 'save=', (strlen($saved) > 10 && str_contains($saved, '<span>')) ? 'ok' : 'fail', "\n";
$path = sys_get_temp_dir() . '/phpc_dom_html_repro_' . getmypid() . '.html';
file_put_contents($path, $html);
$fromFile = Dom\HTMLDocument::createFromFile($path);
@unlink($path);
echo 'file_title=', $fromFile->title, "\n";
$ok = $body !== null
    && $d->title === 'T'
    && $span !== null
    && $span->textContent === 's'
    && $byId !== null
    && $byId->nodeName === 'div'
    && strlen($saved) > 10
    && $fromFile->title === 'T';
echo $ok ? "dom_html_document_surface ok\n" : "dom_html_document_surface fail\n";
