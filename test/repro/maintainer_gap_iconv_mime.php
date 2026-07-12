<?php

declare(strict_types=1);

// Issue #6364 repro — iconv MIME/encoding helpers (ext/iconv/iconv.c).
var_export(is_array(iconv_get_encoding()));
echo "\n";
var_export(iconv_mime_decode('=?UTF-8?B?SGk=?=', ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8'));
echo "\n";
var_export(iconv_set_encoding('input_encoding', 'ISO-8859-1'));
echo "\n";
var_export(iconv_get_encoding('input_encoding'));
echo "\n";
var_export(iconv_mime_encode('Subject', 'über', ['input-charset' => 'UTF-8', 'output-charset' => 'UTF-8']));
echo "\n";
