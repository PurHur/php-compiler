--TEST--
finfo_buffer bare %PDF is text/plain; %PDF-1.4 is application/pdf (#25197)
--FILE--
<?php
$fi = finfo_open(FILEINFO_MIME_TYPE);
echo finfo_buffer($fi, "%PDF"), "\n";
echo finfo_buffer($fi, "%PDF-1.4\n"), "\n";
echo (new finfo(FILEINFO_MIME_TYPE))->buffer("%PDF"), "\n";
echo (new finfo(FILEINFO_MIME_TYPE))->buffer("%PDF-"), "\n";
--EXPECT--
text/plain
application/pdf
text/plain
application/pdf
