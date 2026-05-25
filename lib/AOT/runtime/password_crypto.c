/*
 * password_hash() / password_verify() for AOT/JIT (issue #172 follow-up).
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

extern __string__ *__string__init(long long size, const char *value);

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
