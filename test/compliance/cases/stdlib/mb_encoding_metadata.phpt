--TEST--
stdlib mb encoding metadata — http_output/detect_order/substitute/mime/aliases (ext/mbstring/mbstring.c, #13100)
--FILE--
<?php
echo function_exists('mb_http_output') ? 'yes' : 'no', "\n";
echo function_exists('mb_detect_order') ? 'yes' : 'no', "\n";
echo function_exists('mb_substitute_character') ? 'yes' : 'no', "\n";
echo function_exists('mb_preferred_mime_name') ? 'yes' : 'no', "\n";
echo function_exists('mb_encoding_aliases') ? 'yes' : 'no', "\n";
echo mb_http_output(), "\n";
echo json_encode(mb_detect_order()), "\n";
echo var_export(mb_substitute_character(), true), "\n";
echo mb_preferred_mime_name('UTF-8'), "\n";
echo json_encode(mb_encoding_aliases('UTF-8')), "\n";
echo mb_http_output('SJIS') ? 'set' : 'fail', "\n";
echo mb_http_output(), "\n";
mb_http_output('UTF-8');
echo mb_detect_order('UTF-8,ASCII') ? 'order' : 'fail', "\n";
echo json_encode(mb_detect_order()), "\n";
mb_detect_order();
echo mb_substitute_character('long') ? 'sub' : 'fail', "\n";
echo var_export(mb_substitute_character(), true), "\n";
mb_substitute_character(63);
--EXPECT--
yes
yes
yes
yes
yes
UTF-8
["ASCII","UTF-8"]
63
UTF-8
["utf8"]
set
SJIS
order
["UTF-8","ASCII"]
sub
'long'
