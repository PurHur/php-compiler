--TEST--
AOT: mb_encoding_aliases()/mb_list_encodings() registry fold (#30795, php-src ext/mbstring/mbstring.c)
--FILE--
<?php
echo json_encode(mb_encoding_aliases('UTF-8')), "\n";
echo count(mb_list_encodings()), "\n";
echo count(mb_encoding_aliases('ASCII')), "\n";
try {
    mb_encoding_aliases('nope');
    echo "no throw\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
["utf8"]
78
11
mb_encoding_aliases(): Argument #1 ($encoding) must be a valid encoding, "nope" given
