--TEST--
stdlib ldap_bind_ext registration + Result|false (#22164, ext/ldap/ldap.c)
--FILE--
<?php
declare(strict_types=1);

echo function_exists('ldap_bind_ext') ? "bind_ext=1\n" : "bind_ext=0\n";

$link = ldap_connect('ldap://127.0.0.1');

try {
    ldap_bind_ext(42);
    echo "bad_conn_uncaught\n";
} catch (TypeError $e) {
    echo "bad_conn_typeerror\n";
}

try {
    $r = ldap_bind_ext($link);
    if (false === $r) {
        echo "anon_false\n";
    } elseif (is_object($r)) {
        echo "anon_object\n";
        ldap_free_result($r);
    } else {
        echo "anon_other\n";
    }
} catch (Throwable $e) {
    echo "anon_throw\n";
}

ldap_unbind($link);
?>
--EXPECT--
bind_ext=1
bad_conn_typeerror
anon_false
