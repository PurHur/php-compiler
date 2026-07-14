--TEST--
stdlib mb_send_mail() null byte in additional_headers ValueError (#6548, ext/mbstring/mbstring.c)
--FILE--
<?php
try {
    mb_send_mail('user@example.com', 'subject', 'body', "X-Test: ok\0bad");
    echo "uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
mb_send_mail(): Argument #4 ($additional_headers) must not contain any null bytes
