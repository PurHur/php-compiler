<?php
// Repro #21190 — chunk_split/str_pad/wordwrap/soundex/metaphone/strcmp/strcasecmp soft-null under PROFILE=8.4
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
    }

    return true;
});
echo 'chunk_split ', chunk_split(null) === "\r\n" ? 'OK' : 'BAD', "\n";
echo 'str_pad ', str_pad(null, 5) === '     ' ? 'OK' : 'BAD', "\n";
echo 'wordwrap ', wordwrap(null) === '' ? 'OK' : 'BAD', "\n";
echo 'soundex ', soundex(null) === '0000' ? 'OK' : 'BAD', "\n";
echo 'metaphone ', metaphone(null) === '' ? 'OK' : 'BAD', "\n";
echo 'strcmp ', 0 === strcmp(null, '') ? 'OK' : 'BAD', "\n";
echo 'strcasecmp ', 0 === strcasecmp(null, '') ? 'OK' : 'BAD', "\n";
