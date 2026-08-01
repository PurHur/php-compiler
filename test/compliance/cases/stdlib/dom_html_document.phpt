--TEST--
stdlib Dom\HTMLDocument living surface — body/title/querySelector/getElementById/createFromFile/saveHtml (#6506, #19580, #20540)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo 'HTMLDocument: ', (int) class_exists('Dom\\HTMLDocument'), "\n";
echo 'XMLDocument: ', (int) class_exists('Dom\\XMLDocument'), "\n";
echo 'Document: ', (int) class_exists('Dom\\Document'), "\n";
echo 'Node: ', (int) class_exists('Dom\\Node'), "\n";
echo 'Element: ', (int) class_exists('Dom\\Element'), "\n";
$doc = Dom\HTMLDocument::createFromString('<p>hi</p>');
echo $doc->body->textContent, "\n";
$empty = Dom\HTMLDocument::createEmpty();
// php-src createEmpty() starts with no documentElement / body (#26035).
echo ($empty->body !== null ? 'empty_body' : 'empty_null'), "\n";
echo ($empty->documentElement !== null ? 'empty_root' : 'empty_root_null'), "\n";

$html = '<!DOCTYPE html><html><head><title>T</title></head><body><div id="x"><span>s</span></div></body></html>';
$d = Dom\HTMLDocument::createFromString($html);
$body = $d->body;
echo 'body=', ($body !== null ? $body->nodeName : 'NULL'), "\n";
echo 'title=', $d->title, "\n";
echo 'isset_body=', (int) isset($d->body), ' empty_body=', (int) empty($d->body), "\n";
echo 'isset_title=', (int) isset($d->title), ' empty_title=', (int) empty($d->title), "\n";
$blankTitle = Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><head><title></title></head><body></body></html>');
echo 'blank_isset_title=', (int) isset($blankTitle->title), ' blank_empty_title=', (int) empty($blankTitle->title), "\n";
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
$path = sys_get_temp_dir() . '/phpc_dom_html_' . getmypid() . '.html';
file_put_contents($path, $html);
$fromFile = Dom\HTMLDocument::createFromFile($path);
@unlink($path);
echo 'file_title=', $fromFile->title, "\n";
?>
--EXPECT--
HTMLDocument: 1
XMLDocument: 1
Document: 1
Node: 1
Element: 1
hi
empty_null
empty_root_null
body=BODY
title=T
isset_body=1 empty_body=0
isset_title=1 empty_title=0
blank_isset_title=1 blank_empty_title=1
qs=yes
gid=yes
cff=yes
sh=yes
span=s
id=DIV
save=ok
file_title=T
