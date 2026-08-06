--TEST--
stdlib token_get_all() private(set) → T_PRIVATE_SET under PROFILE=8.4 (#28130, Zend/zend_language_scanner.l)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$code = '<?php class C { public private(set) string $n; }';
$names = [];
foreach (token_get_all($code) as $t) {
    if (is_array($t)) {
        $names[] = token_name($t[0]);
    }
}
echo in_array('T_PRIVATE_SET', $names, true) ? "has_private_set\n" : "missing_private_set\n";
echo in_array('T_PRIVATE', $names, true) ? "still_split_private\n" : "no_split_private\n";
foreach (['T_PRIVATE_SET', 'T_PUBLIC_SET', 'T_PROTECTED_SET', 'T_PROPERTY_C'] as $c) {
    echo $c, '=', defined($c) ? (string) constant($c) : 'UNDEF', "\n";
}
$prop = token_get_all('<?php __PROPERTY__;');
echo is_array($prop[1]) ? token_name($prop[1][0]) : 'not_array', "\n";
?>
--EXPECT--
has_private_set
no_split_private
T_PRIVATE_SET=327
T_PUBLIC_SET=329
T_PROTECTED_SET=328
T_PROPERTY_C=353
T_PROPERTY_C
