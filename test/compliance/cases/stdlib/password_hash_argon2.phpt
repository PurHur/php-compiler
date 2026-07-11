--TEST--
stdlib password_hash() PASSWORD_ARGON2ID — VM libargon2 FFI without host delegation (#4149, #8731)
--SKIPIF--
<?php if (!defined('PASSWORD_ARGON2ID')) { die('skip argon2 unavailable on host'); }
--FILE--
<?php
echo defined('PASSWORD_ARGON2ID') ? "def_argon2id\n" : "undef_argon2id\n";
echo defined('PASSWORD_ARGON2I') ? "def_argon2i\n" : "undef_argon2i\n";

$hash = password_hash('secret', PASSWORD_ARGON2ID);
echo str_starts_with($hash, '$argon2id$') ? "argon2id_prefix\n" : "bad_prefix\n";
echo password_verify('secret', $hash) ? "verify_ok\n" : "verify_fail\n";

$info = password_get_info($hash);
echo ($info['algoName'] ?? '') === 'argon2id' ? "info_ok\n" : "info_fail\n";

$algos = password_algos();
echo in_array('argon2id', $algos, true) ? "algos_argon2id\n" : "algos_missing\n";
$algo = PASSWORD_ARGON2ID;
echo password_needs_rehash($hash, $algo, ['memory_cost' => 1 << 20]) ? "rehash_yes\n" : "rehash_no\n";

$hash2 = password_hash('other', 'argon2i');
echo str_starts_with($hash2, '$argon2i$') ? "string_algo_ok\n" : "string_algo_fail\n";
--EXPECT--
def_argon2id
def_argon2i
argon2id_prefix
verify_ok
info_ok
algos_argon2id
rehash_yes
string_algo_ok
