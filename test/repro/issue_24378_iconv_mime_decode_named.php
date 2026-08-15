<?php
// Issue #24378 — iconv_mime_decode Reflection + named encoding
$s = '=?UTF-8?B?SGVsbG8=?=';
$r = new ReflectionFunction('iconv_mime_decode');
echo implode(',', array_map(static fn($p) => $p->getName(), $r->getParameters())), "\n";
echo iconv_mime_decode($s, mode: 0, encoding: 'UTF-8'), "\n";
