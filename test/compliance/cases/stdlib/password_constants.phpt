--TEST--
stdlib PASSWORD_* constants — string algo ids + defined()/get_defined_constants() (#3620, #11615, #25818)
--FILE--
<?php
echo defined('PASSWORD_DEFAULT') ? "def_default\n" : "undef_default\n";
echo defined('PASSWORD_BCRYPT') ? "def_bcrypt\n" : "undef_bcrypt\n";

$cats = get_defined_constants(true);
// php-src registers PASSWORD_* into the standard extension bucket (not Core).
echo isset($cats['standard']['PASSWORD_DEFAULT']) ? "std_default\n" : "missing_default\n";
echo isset($cats['standard']['PASSWORD_BCRYPT']) ? "std_bcrypt\n" : "missing_bcrypt\n";

echo PASSWORD_BCRYPT === '2y' ? "bcrypt_str\n" : "bcrypt_not_str\n";
echo is_string(PASSWORD_BCRYPT) ? "bcrypt_is_string\n" : "bcrypt_not_string\n";
echo gettype(PASSWORD_BCRYPT) === 'string' ? "bcrypt_gettype_string\n" : "bcrypt_gettype_other\n";

$hash = password_hash('secret', PASSWORD_DEFAULT);
echo password_verify('secret', $hash) ? "verify_ok\n" : "verify_fail\n";

$hash2 = password_hash('other', PASSWORD_BCRYPT);
echo password_verify('other', $hash2) ? "bcrypt_ok\n" : "bcrypt_fail\n";

if (!defined('PASSWORD_ARGON2I') || !defined('PASSWORD_ARGON2ID')) {
    echo "argon2_skip\n";
} else {
    echo isset($cats['standard']['PASSWORD_ARGON2I']) ? "std_argon2i\n" : "missing_argon2i\n";
    echo isset($cats['standard']['PASSWORD_ARGON2ID']) ? "std_argon2id\n" : "missing_argon2id\n";
    // ConstFetch must yield strings (not internal VmPassword int ids 2/3) — #11615 / #25818.
    echo is_string(PASSWORD_ARGON2I) && PASSWORD_ARGON2I === 'argon2i' ? "argon2i_str\n" : "argon2i_bad\n";
    echo is_string(PASSWORD_ARGON2ID) && PASSWORD_ARGON2ID === 'argon2id' ? "argon2id_str\n" : "argon2id_bad\n";
    echo gettype(PASSWORD_ARGON2I) === 'string' ? "argon2i_gettype_string\n" : "argon2i_gettype_other\n";
    echo gettype(PASSWORD_ARGON2ID) === 'string' ? "argon2id_gettype_string\n" : "argon2id_gettype_other\n";
    $assigned = PASSWORD_ARGON2ID;
    echo is_string($assigned) && $assigned === 'argon2id' ? "argon2id_assign_str\n" : "argon2id_assign_bad\n";
    $hash3 = password_hash('x', PASSWORD_ARGON2ID);
    echo (is_string($hash3) && str_starts_with($hash3, '$argon2id$') && password_verify('x', $hash3))
        ? "argon2id_hash_ok\n" : "argon2id_hash_fail\n";
}
--EXPECT--
def_default
def_bcrypt
std_default
std_bcrypt
bcrypt_str
bcrypt_is_string
bcrypt_gettype_string
verify_ok
bcrypt_ok
std_argon2i
std_argon2id
argon2i_str
argon2id_str
argon2i_gettype_string
argon2id_gettype_string
argon2id_assign_str
argon2id_hash_ok
