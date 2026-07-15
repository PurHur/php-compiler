--TEST--
stdlib mb_send_mail() enum operand TypeError (#6548, php-src-strict)
--FILE--
<?php
enum E: string { case A = 'user@example.com'; }
try {
    mb_send_mail(E::A, 'subject', 'body');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
mb_send_mail(): Argument #1 ($to) must be of type string, E given
