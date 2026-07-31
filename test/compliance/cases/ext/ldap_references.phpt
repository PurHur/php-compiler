--TEST--
stdlib ldap_count/first/next/parse_reference helpers (#22181, ext/ldap/ldap.c)
--ENV--
PHP_COMPILER_ENABLE_LDAP=1
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$fns = [
    'ldap_count_references',
    'ldap_first_reference',
    'ldap_next_reference',
    'ldap_parse_reference',
];
foreach ($fns as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}

$link = ldap_connect('ldap://127.0.0.1');

try {
    ldap_count_references(42, $link);
    echo "count_bad_conn_uncaught\n";
} catch (TypeError $e) {
    echo "count_bad_conn_typeerror\n";
}

try {
    ldap_first_reference($link, $link);
    echo "first_bad_result_uncaught\n";
} catch (TypeError $e) {
    echo "first_bad_result_typeerror\n";
}

try {
    ldap_next_reference($link, $link);
    echo "next_bad_entry_uncaught\n";
} catch (TypeError $e) {
    echo "next_bad_entry_typeerror\n";
}

try {
    $refs = null;
    ldap_parse_reference($link, $link, $refs);
    echo "parse_bad_entry_uncaught\n";
} catch (TypeError $e) {
    echo "parse_bad_entry_typeerror\n";
}

ldap_unbind($link);
?>
--EXPECT--
ldap_count_references=1
ldap_first_reference=1
ldap_next_reference=1
ldap_parse_reference=1
count_bad_conn_typeerror
first_bad_result_typeerror
next_bad_entry_typeerror
parse_bad_entry_typeerror
