/*
 * strtok() runtime for VM/JIT/AOT (issue #3201).
 * Mirrors php-src ext/standard/string.c PHP_FUNCTION(strtok) byte semantics.
 */

#include <stdint.h>
#include <string.h>

#define PHPC_STRTOK_MAX 65536

typedef struct __string__ __string__;

extern __string__ *__string__init(long long size, const char *value);

static char strtok_buf[PHPC_STRTOK_MAX];
static size_t strtok_len = 0;
static const char *strtok_last = NULL;

static size_t phpc_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *phpc_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static void phpc_strtok_reset(void)
{
    strtok_len = 0;
    strtok_last = NULL;
    strtok_buf[0] = '\0';
}

static void phpc_strtok_init(__string__ *str)
{
    const char *data;
    size_t len;

    phpc_strtok_reset();
    if (NULL == str) {
        return;
    }
    data = phpc_strdata(str);
    len = phpc_strlen(str);
    if (len >= PHPC_STRTOK_MAX) {
        len = PHPC_STRTOK_MAX - 1;
    }
    if (len > 0) {
        memcpy(strtok_buf, data, len);
    }
    strtok_buf[len] = '\0';
    strtok_len = len;
    strtok_last = strtok_buf;
}

__string__ *phpc_strtok(__string__ *str, __string__ *tok, int8_t init_string)
{
    unsigned char table[256];
    const char *p;
    const char *pe;
    const char *token;
    const char *token_end;
    size_t skipped;
    size_t token_len;

    if (0 != init_string) {
        phpc_strtok_init(str);
    } else if (NULL == strtok_last) {
        return NULL;
    }

    if (NULL == tok) {
        return NULL;
    }

    p = strtok_last;
    pe = strtok_buf + strtok_len;
    if (p >= pe) {
        phpc_strtok_reset();

        return NULL;
    }

    memset(table, 0, sizeof(table));
    token = phpc_strdata(tok);
    token_end = token + phpc_strlen(tok);
    while (token < token_end) {
        table[(unsigned char) *token++] = 1;
    }

    skipped = 0;
    while (table[(unsigned char) *p]) {
        if (++p >= pe) {
            phpc_strtok_reset();

            return NULL;
        }
        skipped++;
    }

    while (++p < pe) {
        if (table[(unsigned char) *p]) {
            const char *start = strtok_last + skipped;

            token_len = (size_t) (p - strtok_last) - skipped;
            strtok_last = p + 1;

            return __string__init((long long) token_len, start);
        }
    }

    if (p > strtok_last) {
        const char *start = strtok_last + skipped;

        token_len = (size_t) (p - strtok_last) - skipped;
        phpc_strtok_reset();

        return __string__init((long long) token_len, start);
    }

    phpc_strtok_reset();

    return NULL;
}
