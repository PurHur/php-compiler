/*
 * password_hash() / password_verify() / password_get_info() for AOT/JIT.
 * PASSWORD_DEFAULT / PASSWORD_BCRYPT via libcrypt(3); no OpenSSL.
 */

#include <errno.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/random.h>
#include <unistd.h>

#include <crypt.h>

typedef struct __string__ __string__;
typedef struct __hashtable__ __hashtable__;

extern __hashtable__ *__hashtable__alloc(void);
extern void __hashtable__setStringKeyString(__hashtable__ *ht, __string__ *key, __string__ *val);
extern void __hashtable__setStringKeyLong(__hashtable__ *ht, __string__ *key, long long val);
extern void __hashtable__setStringKeyHashtable(__hashtable__ *ht, __string__ *key, __hashtable__ *child);

extern __string__ *__string__init(long long size, const char *value);

static int pc_is_valid_salt_char(char c)
{
    return (c >= '.' && c <= '9') || (c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z');
}

static const char BCRYPT_ITOA64[] =
    "./ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";

#define PASSWORD_BCRYPT 1
#define PASSWORD_DEFAULT 1
#define BCRYPT_DEFAULT_COST 10

static size_t pc_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *pc_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static int pc_fill_random(unsigned char *buf, size_t len)
{
#if defined(__linux__)
    ssize_t got = 0;

    while ((size_t) got < len) {
        ssize_t n = getrandom(buf + got, len - (size_t) got, 0);
        if (n < 0) {
            if (EINTR == errno) {
                continue;
            }
            return 0;
        }
        if (0 == n) {
            return 0;
        }
        got += n;
    }

    return 1;
#else
    (void) buf;
    (void) len;

    return 0;
#endif
}

/** Encode 16 random bytes into 22 bcrypt salt characters (PHP-compatible). */
static void pc_bcrypt_encode_salt22(char out[23], const unsigned char src[16])
{
    size_t i = 0;
    size_t o = 0;

    while (o < 22) {
        unsigned int c1 = i < 16 ? src[i++] : 0;
        unsigned int c2 = i < 16 ? src[i++] : 0;
        out[o++] = BCRYPT_ITOA64[c1 >> 2];
        out[o++] = BCRYPT_ITOA64[((c1 & 0x03) << 4) | (c2 >> 4)];
        if (o >= 22) {
            break;
        }
        unsigned int c3 = i < 16 ? src[i++] : 0;
        out[o++] = BCRYPT_ITOA64[((c2 & 0x0f) << 2) | (c3 >> 6)];
        if (o >= 22) {
            break;
        }
        out[o++] = BCRYPT_ITOA64[c3 & 0x3f];
    }
    out[22] = '\0';
}

static __string__ *pc_string_from_cstr(const char *value)
{
    if (NULL == value) {
        return NULL;
    }
    size_t len = strlen(value);

    return __string__init((long long) len, value);
}

static int pc_algo_supported(int64_t algo)
{
    return algo == PASSWORD_BCRYPT || algo == PASSWORD_DEFAULT;
}

__string__ *__compiler_password_hash(__string__ *password, int64_t algo)
{
    if (!pc_algo_supported(algo)) {
        return NULL;
    }
    const char *phrase = pc_strdata(password);
    unsigned char rnd[16];
    char setting[64];
    char salt22[24];
    char *result;

    if (!pc_fill_random(rnd, sizeof(rnd))) {
        return NULL;
    }
    pc_bcrypt_encode_salt22(salt22, rnd);
    if (snprintf(setting, sizeof(setting), "$2y$%02d$%s", BCRYPT_DEFAULT_COST, salt22)
        >= (int) sizeof(setting)) {
        return NULL;
    }
    result = crypt(phrase, setting);
    if (NULL == result || result[0] == '*') {
        return NULL;
    }

    return pc_string_from_cstr(result);
}

__string__ *__compiler_crypt(__string__ *password, __string__ *salt)
{
    const char *phrase = pc_strdata(password);
    const char *setting = pc_strdata(salt);
    size_t salt_len = pc_strlen(salt);
    char *result;

    if (salt_len >= 2 && setting[0] == '*' && (setting[1] == '0' || setting[1] == '1')) {
        return pc_string_from_cstr("*0");
    }

    if (salt_len > 0 && setting[0] == '$') {
        if (salt_len >= 4 && setting[1] == '2' && setting[3] == '$') {
            /* blowfish — libcrypt */
        } else if (salt_len >= 3 && setting[1] == '1' && setting[2] == '$') {
            /* md5-crypt — libcrypt */
        } else {
            return pc_string_from_cstr("*0");
        }
    } else if (salt_len >= 2) {
        if (!pc_is_valid_salt_char(setting[0]) || !pc_is_valid_salt_char(setting[1])) {
            return pc_string_from_cstr("*0");
        }
    } else {
        return pc_string_from_cstr("*0");
    }

    result = crypt(phrase, setting);
    if (NULL == result || result[0] == '*' || 0 == strcmp(result, "*")) {
        return pc_string_from_cstr("*0");
    }

    return pc_string_from_cstr(result);
}

int __compiler_password_verify(__string__ *password, __string__ *hash)
{
    const char *phrase = pc_strdata(password);
    const char *stored = pc_strdata(hash);
    size_t stored_len = pc_strlen(hash);
    char *computed;

    if (stored_len < 29 || strncmp(stored, "$2y$", 4) != 0) {
        return 0;
    }
    computed = crypt(phrase, stored);
    if (NULL == computed || computed[0] == '*') {
        return 0;
    }

    return strcmp(computed, stored) == 0;
}

static int pc_bcrypt_valid(const char *h, size_t len)
{
    return len == 60 && h[0] == '$' && h[1] == '2' && h[2] == 'y';
}

static int pc_extract_ident(const char *h, size_t len, char *ident, size_t cap)
{
    const char *start;
    const char *end;
    size_t idlen;

    if (len < 3 || h[0] != '$') {
        return 0;
    }
    start = h + 1;
    end = strchr(start, '$');
    if (NULL == end) {
        return 0;
    }
    idlen = (size_t) (end - start);
    if (idlen + 1 > cap) {
        return 0;
    }
    memcpy(ident, start, idlen);
    ident[idlen] = '\0';

    return 1;
}

static __hashtable__ *pc_password_info_unknown(void)
{
    __hashtable__ *ht = __hashtable__alloc();
    __hashtable__ *opts = __hashtable__alloc();

    __hashtable__setStringKeyString(ht, pc_string_from_cstr("algoName"), pc_string_from_cstr("unknown"));
    __hashtable__setStringKeyHashtable(ht, pc_string_from_cstr("options"), opts);

    return ht;
}

static __hashtable__ *pc_password_info_bcrypt(const char *h)
{
    long long cost = 10;
    __hashtable__ *ht;
    __hashtable__ *opts;

    sscanf(h, "$2y$%lld$", &cost);
    ht = __hashtable__alloc();
    opts = __hashtable__alloc();
    __hashtable__setStringKeyString(ht, pc_string_from_cstr("algo"), pc_string_from_cstr("2y"));
    __hashtable__setStringKeyString(ht, pc_string_from_cstr("algoName"), pc_string_from_cstr("bcrypt"));
    __hashtable__setStringKeyLong(opts, pc_string_from_cstr("cost"), cost);
    __hashtable__setStringKeyHashtable(ht, pc_string_from_cstr("options"), opts);

    return ht;
}

static __hashtable__ *pc_password_info_argon2(const char *h, const char *name)
{
    long long v = 0;
    long long memory_cost = 65536;
    long long time_cost = 4;
    long long threads = 1;
    __hashtable__ *ht;
    __hashtable__ *opts;

    sscanf(h, "v=%lld$m=%lld,t=%lld,p=%lld", &v, &memory_cost, &time_cost, &threads);
    ht = __hashtable__alloc();
    opts = __hashtable__alloc();
    __hashtable__setStringKeyString(ht, pc_string_from_cstr("algo"), pc_string_from_cstr(name));
    __hashtable__setStringKeyString(ht, pc_string_from_cstr("algoName"), pc_string_from_cstr(name));
    __hashtable__setStringKeyLong(opts, pc_string_from_cstr("memory_cost"), memory_cost);
    __hashtable__setStringKeyLong(opts, pc_string_from_cstr("time_cost"), time_cost);
    __hashtable__setStringKeyLong(opts, pc_string_from_cstr("threads"), threads);
    __hashtable__setStringKeyHashtable(ht, pc_string_from_cstr("options"), opts);

    return ht;
}

__hashtable__ *__compiler_password_get_info(__string__ *hash)
{
    const char *h = pc_strdata(hash);
    size_t len = pc_strlen(hash);
    char ident[32];

    if (!pc_extract_ident(h, len, ident, sizeof(ident))) {
        return pc_password_info_unknown();
    }
    if (strcmp(ident, "2y") == 0 && pc_bcrypt_valid(h, len)) {
        return pc_password_info_bcrypt(h);
    }
    if (strcmp(ident, "argon2i") == 0 && len >= sizeof("$argon2i$") - 1
        && !memcmp(h, "$argon2i$", sizeof("$argon2i$") - 1)) {
        return pc_password_info_argon2(h + sizeof("$argon2i$") - 1, "argon2i");
    }
    if (strcmp(ident, "argon2id") == 0 && len >= sizeof("$argon2id$") - 1
        && !memcmp(h, "$argon2id$", sizeof("$argon2id$") - 1)) {
        return pc_password_info_argon2(h + sizeof("$argon2id$") - 1, "argon2id");
    }

    return pc_password_info_unknown();
}
