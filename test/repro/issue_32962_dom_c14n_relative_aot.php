<?php
// Relative-NS must stay false (#22378) after #32962 C14N AOT fix.
$rel = new DOMDocument();
$rel->loadXML('<r xmlns:p="u"><p:a>x</p:a></r>');
echo (false === @$rel->documentElement->C14N()) ? 'rel|' : 'rel-fail|';
$abs = new DOMDocument();
$abs->loadXML('<r xmlns="http://example.com"><a>x</a></r>');
$want = '<r xmlns="http://example.com"><a>x</a></r>';
echo (@$abs->documentElement->C14N() === $want) ? 'abs' : 'abs-fail';
