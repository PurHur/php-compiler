<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gnupg;

/**
 * libgpgme FFI bridge (PECL gnupg / gnupg.c; #6668).
 */
final class VmGnupgNative
{
    public const PROTOCOL_OPENPGP = 0;

    public const ENCRYPT_ALWAYS_TRUST = 0x80;

    public const SIG_MODE_NORMAL = 0;

    public const SIG_MODE_DETACH = 1;

    public const SIG_MODE_CLEAR = 2;

    public const ATTR_KEYID = 0;

    public const ATTR_FPR = 1;

    public const ATTR_ALGO = 2;

    public const ATTR_LEN = 3;

    public const ATTR_CREATED = 4;

    public const ATTR_EXPIRE = 5;

    public const ATTR_USERID = 7;

    public const ATTR_NAME = 8;

    public const ATTR_EMAIL = 9;

    public const ATTR_COMMENT = 10;

    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    /** @var list<\FFI\CData> */
    private static array $passphraseCallbacks = [];

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function checkVersion(): void
    {
        self::requireFfi()->gpgme_check_version(null);
    }

    /**
     * @return \FFI\CData|null gpgme_ctx_t
     */
    public static function ctxNew(): ?\FFI\CData
    {
        $ffi = self::requireFfi();
        $ctx = $ffi->new('gpgme_ctx_t');
        $err = (int) $ffi->gpgme_new(\FFI::addr($ctx));
        if (0 !== $err) {
            return null;
        }

        return $ctx;
    }

    public static function ctxRelease(\FFI\CData $ctx): void
    {
        self::requireFfi()->gpgme_release($ctx);
    }

    public static function ctxSetEngineInfo(
        \FFI\CData $ctx,
        ?string $fileName,
        ?string $homeDir
    ): int {
        return (int) self::requireFfi()->gpgme_ctx_set_engine_info(
            $ctx,
            self::PROTOCOL_OPENPGP,
            $fileName,
            $homeDir
        );
    }

    public static function setArmor(\FFI\CData $ctx, int $armor): void
    {
        self::requireFfi()->gpgme_set_armor($ctx, $armor);
    }

    public static function setPinentryModeLoopback(\FFI\CData $ctx): void
    {
        if (!\method_exists(self::requireFfi(), 'gpgme_set_pinentry_mode')) {
            return;
        }
        // GPGME_PINENTRY_MODE_LOOPBACK
        self::requireFfi()->gpgme_set_pinentry_mode($ctx, 3);
    }

    /**
     * @return array{0: \FFI\CData|null, 1: int} key + gpgme_error_t
     */
    public static function getKey(\FFI\CData $ctx, string $keyId, bool $secret): array
    {
        $ffi = self::requireFfi();
        $key = $ffi->new('gpgme_key_t');
        $err = (int) $ffi->gpgme_get_key($ctx, $keyId, \FFI::addr($key), $secret ? 1 : 0);
        if (0 !== $err) {
            return [null, $err];
        }

        return [$key, 0];
    }

    public static function keyUnref(?\FFI\CData $key): void
    {
        if (null === $key) {
            return;
        }
        self::requireFfi()->gpgme_key_unref($key);
    }

    /**
     * @return array{0: \FFI\CData|null, 1: int}
     */
    public static function dataNew(): array
    {
        $ffi = self::requireFfi();
        $dh = $ffi->new('gpgme_data_t');
        $err = (int) $ffi->gpgme_data_new(\FFI::addr($dh));
        if (0 !== $err) {
            return [null, $err];
        }

        return [$dh, 0];
    }

    /**
     * @return array{0: \FFI\CData|null, 1: int}
     */
    public static function dataNewFromMem(string $buffer, bool $copy = false): array
    {
        $ffi = self::requireFfi();
        $dh = $ffi->new('gpgme_data_t');
        $err = (int) $ffi->gpgme_data_new_from_mem(
            \FFI::addr($dh),
            $buffer,
            \strlen($buffer),
            $copy ? 1 : 0
        );
        if (0 !== $err) {
            return [null, $err];
        }

        return [$dh, 0];
    }

    public static function dataRelease(?\FFI\CData $dh): void
    {
        if (null === $dh) {
            return;
        }
        self::requireFfi()->gpgme_data_release($dh);
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    public static function dataReleaseAndGetMem(\FFI\CData $dh): ?array
    {
        $ffi = self::requireFfi();
        $len = $ffi->new('size_t');
        $mem = $ffi->gpgme_data_release_and_get_mem($dh, \FFI::addr($len));
        if (null === $mem) {
            return null;
        }
        $size = (int) $len->cdata;
        if ($size < 1) {
            $ffi->gpgme_free($mem);

            return ['', 0];
        }
        $str = \FFI::string($mem, $size);
        $ffi->gpgme_free($mem);

        return [$str, $size];
    }

    /**
     * @param list<\FFI\CData> $recipients
     *
     * @return int gpgme_error_t
     */
    public static function opEncrypt(
        \FFI\CData $ctx,
        array $recipients,
        \FFI\CData $plain,
        \FFI\CData $cipher
    ): int {
        $ffi = self::requireFfi();
        $n = \count($recipients);
        $arr = $ffi->new('gpgme_key_t['.($n + 1).']');
        for ($i = 0; $i < $n; ++$i) {
            $arr[$i] = $recipients[$i];
        }
        $arr[$n] = null;

        return (int) $ffi->gpgme_op_encrypt($ctx, $arr, self::ENCRYPT_ALWAYS_TRUST, $plain, $cipher);
    }

    /**
     * @return \FFI\CData|null gpgme_encrypt_result_t
     */
    public static function opEncryptResult(\FFI\CData $ctx): ?\FFI\CData
    {
        return self::requireFfi()->gpgme_op_encrypt_result($ctx);
    }

    public static function opDecrypt(\FFI\CData $ctx, \FFI\CData $cipher, \FFI\CData $plain): int
    {
        return (int) self::requireFfi()->gpgme_op_decrypt($ctx, $cipher, $plain);
    }

    /**
     * @return \FFI\CData|null
     */
    public static function opDecryptResult(\FFI\CData $ctx): ?\FFI\CData
    {
        return self::requireFfi()->gpgme_op_decrypt_result($ctx);
    }

    public static function opSign(
        \FFI\CData $ctx,
        \FFI\CData $unsignedText,
        \FFI\CData $signedText,
        int $mode
    ): int {
        return (int) self::requireFfi()->gpgme_op_sign($ctx, $unsignedText, $signedText, $mode);
    }

    /**
     * @return \FFI\CData|null
     */
    public static function opSignResult(\FFI\CData $ctx): ?\FFI\CData
    {
        return self::requireFfi()->gpgme_op_sign_result($ctx);
    }

    public static function opVerify(
        \FFI\CData $ctx,
        \FFI\CData $sig,
        ?\FFI\CData $signedText,
        ?\FFI\CData $plain
    ): int {
        return (int) self::requireFfi()->gpgme_op_verify(
            $ctx,
            $sig,
            $signedText,
            $plain
        );
    }

    /**
     * @return \FFI\CData|null
     */
    public static function opVerifyResult(\FFI\CData $ctx): ?\FFI\CData
    {
        return self::requireFfi()->gpgme_op_verify_result($ctx);
    }

    public static function opKeylistStart(\FFI\CData $ctx, string $pattern, bool $secretOnly): int
    {
        return (int) self::requireFfi()->gpgme_op_keylist_start($ctx, $pattern, $secretOnly ? 1 : 0);
    }

    /**
     * @return array{0: \FFI\CData|null, 1: int}
     */
    public static function opKeylistNext(\FFI\CData $ctx): array
    {
        $ffi = self::requireFfi();
        $key = $ffi->new('gpgme_key_t');
        $err = (int) $ffi->gpgme_op_keylist_next($ctx, \FFI::addr($key));
        if (0 !== $err) {
            return [null, $err];
        }

        return [$key, 0];
    }

    public static function opKeylistEnd(\FFI\CData $ctx): int
    {
        return (int) self::requireFfi()->gpgme_op_keylist_end($ctx);
    }

    public static function signersAdd(\FFI\CData $ctx, \FFI\CData $key): int
    {
        return (int) self::requireFfi()->gpgme_signers_add($ctx, $key);
    }

    public static function signersClear(\FFI\CData $ctx): void
    {
        self::requireFfi()->gpgme_signers_clear($ctx);
    }

    public static function strError(int $err): string
    {
        $msg = self::requireFfi()->gpgme_strerror($err);
        if (null === $msg || false === $msg) {
            return '';
        }

        return (string) $msg;
    }

    public static function strSource(int $err): string
    {
        $msg = self::requireFfi()->gpgme_strsource($err);
        if (null === $msg || false === $msg) {
            return '';
        }

        return (string) $msg;
    }

    public static function keyGetStringAttr(\FFI\CData $key, int $which, int $idx): ?string
    {
        $val = self::requireFfi()->gpgme_key_get_string_attr($key, $which, $idx);
        if (null === $val || false === $val) {
            return null;
        }

        return (string) $val;
    }

    public static function keyGetUlongAttr(\FFI\CData $key, int $which, int $idx): int
    {
        return (int) self::requireFfi()->gpgme_key_get_ulong_attr($key, $which, $idx);
    }

    /**
     * @param callable(int): (string|null) $lookupPassphrase by 16-char key id prefix
     */
    public static function setPassphraseCbSign(\FFI\CData $ctx, callable $lookupPassphrase): void
    {
        $cb = \FFI::callback('gpgme_error_t (*)(void *, const char *, const char *, int, int)', static function (
            $hook,
            $uidHint,
            $passphraseInfo,
            $lastWasBad,
            $fd
        ) use ($lookupPassphrase): int {
            if (0 !== (int) $lastWasBad) {
                return 1;
            }
            $uid = self::uidHintPrefix($uidHint);
            $passphrase = $lookupPassphrase($uid);
            if (null === $passphrase) {
                return 1;
            }
            self::writePassphraseFd((int) $fd, $passphrase);

            return 0;
        }, null);
        self::$passphraseCallbacks[] = $cb;
        self::requireFfi()->gpgme_set_passphrase_cb($ctx, $cb, null);
    }

    /**
     * @param callable(int): (string|null) $lookupPassphrase
     */
    public static function setPassphraseCbDecrypt(\FFI\CData $ctx, callable $lookupPassphrase): void
    {
        $cb = \FFI::callback('gpgme_error_t (*)(void *, const char *, const char *, int, int)', static function (
            $hook,
            $uidHint,
            $passphraseInfo,
            $lastWasBad,
            $fd
        ) use ($lookupPassphrase): int {
            if (0 !== (int) $lastWasBad) {
                return 1;
            }
            if (null === $uidHint) {
                return 1;
            }
            $uid = self::uidHintPrefix($uidHint);
            $passphrase = $lookupPassphrase($uid);
            if (null === $passphrase) {
                self::writePassphraseFd((int) $fd, '');

                return 0;
            }
            self::writePassphraseFd((int) $fd, $passphrase);

            return 0;
        }, null);
        self::$passphraseCallbacks[] = $cb;
        self::requireFfi()->gpgme_set_passphrase_cb($ctx, $cb, null);
    }

    /** @return \FFI */
    private static function requireFfi()
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            throw new \LogicException('gnupg requires libgpgme FFI (#6668)');
        }

        return $ffi;
    }

    /** @return \FFI|null */
    private static function ffi()
    {
        if (!self::ffiEnabled()) {
            return null;
        }
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef struct gpgme_context *gpgme_ctx_t;
typedef struct gpgme_data *gpgme_data_t;
typedef struct gpgme_key *gpgme_key_t;
typedef int gpgme_error_t;
typedef int gpgme_protocol_t;
typedef unsigned int gpgme_encrypt_flags_t;
typedef int gpgme_sig_mode_t;
typedef int gpgme_attr_t;

typedef struct gpgme_invalid_key *gpgme_invalid_key_t;
typedef struct {
  gpgme_invalid_key_t invalid_recipients;
} *gpgme_encrypt_result_t;

typedef struct {
  int unsupported_algorithm;
} *gpgme_decrypt_result_t;

typedef struct gpgme_signature *gpgme_signature_t;
typedef struct gpgme_invalid_key *gpgme_invalid_signer_t;
typedef struct {
  gpgme_signature_t signatures;
  gpgme_invalid_signer_t invalid_signers;
} *gpgme_sign_result_t;

typedef struct {
  gpgme_signature_t signatures;
} *gpgme_verify_result_t;

struct gpgme_signature {
  gpgme_signature_t next;
  char *fpr;
  int validity;
  long int timestamp;
  gpgme_error_t status;
  unsigned int summary;
};

struct gpgme_key {
  unsigned long _refs;
  unsigned int revoked;
  unsigned int expired;
  unsigned int disabled;
  unsigned int invalid;
  unsigned int secret;
  unsigned int can_encrypt;
  unsigned int can_sign;
  unsigned int can_certify;
  unsigned int is_qualified;
  gpgme_protocol_t protocol;
  char *issuer_serial;
  char *issuer_name;
  char *chain_id;
  char *owner_trust;
  void *subkeys;
  void *uids;
  char *fpr;
  char *uid;
  char *last_update;
  char *ttl;
  char *subkey;
  char *keyid;
  char *fingerprint;
  char *comment;
  char *name;
  char *email;
};

struct gpgme_user_id {
  struct gpgme_user_id *next;
  unsigned int revoked;
  unsigned int invalid;
  char *uid;
  char *name;
  char *email;
  char *comment;
};

struct gpgme_subkey {
  struct gpgme_subkey *next;
  char *keyid;
  unsigned int revoked;
  unsigned int expired;
  unsigned int disabled;
  unsigned int invalid;
  unsigned int secret;
  unsigned int can_encrypt;
  unsigned int can_sign;
  unsigned int can_certify;
  unsigned int can_authenticate;
  unsigned int is_qualified;
  unsigned int is_cardkey;
  unsigned int is_de_vs;
  int pubkey_algo;
  char *fpr;
  unsigned int _reserved;
  unsigned long timestamp;
  unsigned long expires;
  char *keygrip;
  char *curve;
  char *card_number;
  unsigned int length;
};

typedef gpgme_error_t (*gpgme_passphrase_cb_t)(void *hook, const char *uid_hint,
  const char *passphrase_info, int last_was_bad, int fd);

const char *gpgme_check_version(const char *req_version);
gpgme_error_t gpgme_new(gpgme_ctx_t *ctx);
void gpgme_release(gpgme_ctx_t ctx);
gpgme_error_t gpgme_set_protocol(gpgme_ctx_t ctx, gpgme_protocol_t proto);
void gpgme_set_armor(gpgme_ctx_t ctx, int yes);
gpgme_error_t gpgme_ctx_set_engine_info(gpgme_ctx_t ctx, gpgme_protocol_t proto,
  const char *file_name, const char *home_dir);
gpgme_error_t gpgme_get_key(gpgme_ctx_t ctx, const char *fpr, gpgme_key_t *key, int secret);
void gpgme_key_unref(gpgme_key_t key);
gpgme_error_t gpgme_data_new(gpgme_data_t *dh);
gpgme_error_t gpgme_data_new_from_mem(gpgme_data_t *dh, const char *buffer, size_t size, int copy);
void gpgme_data_release(gpgme_data_t dh);
char *gpgme_data_release_and_get_mem(gpgme_data_t dh, size_t *len);
void gpgme_free(void *ptr);
gpgme_error_t gpgme_op_encrypt(gpgme_ctx_t ctx, gpgme_key_t recp[], gpgme_encrypt_flags_t flags,
  gpgme_data_t plain, gpgme_data_t cipher);
gpgme_encrypt_result_t gpgme_op_encrypt_result(gpgme_ctx_t ctx);
gpgme_error_t gpgme_op_decrypt(gpgme_ctx_t ctx, gpgme_data_t cipher, gpgme_data_t plain);
gpgme_decrypt_result_t gpgme_op_decrypt_result(gpgme_ctx_t ctx);
gpgme_error_t gpgme_op_sign(gpgme_ctx_t ctx, gpgme_data_t unsigned_text, gpgme_data_t signed_text,
  gpgme_sig_mode_t mode);
gpgme_sign_result_t gpgme_op_sign_result(gpgme_ctx_t ctx);
gpgme_error_t gpgme_op_verify(gpgme_ctx_t ctx, gpgme_data_t sig, gpgme_data_t signed_text,
  gpgme_data_t plain);
gpgme_verify_result_t gpgme_op_verify_result(gpgme_ctx_t ctx);
gpgme_error_t gpgme_op_keylist_start(gpgme_ctx_t ctx, const char *pattern, int secret_only);
gpgme_error_t gpgme_op_keylist_next(gpgme_ctx_t ctx, gpgme_key_t *key);
gpgme_error_t gpgme_op_keylist_end(gpgme_ctx_t ctx);
gpgme_error_t gpgme_signers_add(gpgme_ctx_t ctx, gpgme_key_t key);
void gpgme_signers_clear(gpgme_ctx_t ctx);
const char *gpgme_strerror(gpgme_error_t err);
const char *gpgme_strsource(gpgme_error_t err);
void gpgme_set_passphrase_cb(gpgme_ctx_t ctx, gpgme_passphrase_cb_t cb, void *hook);
const char *gpgme_key_get_string_attr(gpgme_key_t key, gpgme_attr_t which, int idx);
unsigned long gpgme_key_get_ulong_attr(gpgme_key_t key, gpgme_attr_t which, int idx);
gpgme_error_t gpgme_set_pinentry_mode(gpgme_ctx_t ctx, int mode);
CDEF;

        foreach (['libgpgme.so.11', 'libgpgme.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);
                self::checkVersion();

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return true;
    }

    private static function uidHintPrefix(?\FFI\CData $uidHint): string
    {
        if (null === $uidHint) {
            return '';
        }
        $hint = (string) $uidHint;
        $uid = '';
        for ($idx = 0; $idx < 16 && isset($hint[$idx]) && "\0" !== $hint[$idx]; ++$idx) {
            $uid .= $hint[$idx];
        }

        return $uid;
    }

    private static function writePassphraseFd(int $fd, string $passphrase): void
    {
        $stream = @\fopen('php://fd/'.$fd, 'wb');
        if (false === $stream) {
            return;
        }
        \fwrite($stream, $passphrase."\n");
        \fclose($stream);
    }
}
