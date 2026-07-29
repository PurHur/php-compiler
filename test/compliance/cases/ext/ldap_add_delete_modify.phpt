--TEST--
stdlib ldap_add/delete/modify/modify_batch + *_ext registration (#22196, ext/ldap/ldap.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$fns = [
    'ldap_add',
    'ldap_delete',
    'ldap_modify',
    'ldap_modify_batch',
    'ldap_add_ext',
    'ldap_delete_ext',
    'ldap_rename_ext',
    'ldap_mod_add_ext',
    'ldap_mod_del_ext',
    'ldap_mod_replace_ext',
];
foreach ($fns as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}

$link = ldap_connect('ldap://127.0.0.1');

try {
    ldap_add(42, 'cn=x', ['cn' => 'a']);
    echo "add_bad_conn_uncaught\n";
} catch (TypeError $e) {
    echo "add_bad_conn_typeerror\n";
}

try {
    ldap_delete($link, 'cn=x,dc=example,dc=com');
    echo "delete_callable\n";
} catch (Throwable $e) {
    echo "delete_throw\n";
}

try {
    ldap_modify($link, 'cn=x,dc=example,dc=com', ['cn' => 'y']);
    echo "modify_callable\n";
} catch (Throwable $e) {
    echo "modify_throw\n";
}

try {
    $modtype = LDAP_MODIFY_BATCH_REPLACE;
    $batch = [
        [
            'attrib' => 'cn',
            'modtype' => $modtype,
            'values' => ['z'],
        ],
    ];
    ldap_modify_batch($link, 'cn=x,dc=example,dc=com', $batch);
    echo "batch_callable\n";
} catch (Throwable $e) {
    echo "batch_throw\n";
}

try {
    $r = ldap_add_ext($link, 'cn=x,dc=example,dc=com', ['cn' => 'a', 'objectClass' => 'top']);
    echo is_object($r) ? "add_ext_object\n" : (false === $r ? "add_ext_false\n" : "add_ext_other\n");
} catch (Throwable $e) {
    echo "add_ext_throw\n";
}

ldap_unbind($link);
?>
--EXPECT--
ldap_add=1
ldap_delete=1
ldap_modify=1
ldap_modify_batch=1
ldap_add_ext=1
ldap_delete_ext=1
ldap_rename_ext=1
ldap_mod_add_ext=1
ldap_mod_del_ext=1
ldap_mod_replace_ext=1
add_bad_conn_typeerror
delete_callable
modify_callable
batch_callable
add_ext_false
