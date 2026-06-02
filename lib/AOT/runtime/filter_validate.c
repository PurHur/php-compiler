/*
 * filter_var() FILTER_VALIDATE_EMAIL for AOT/JIT (issue #104).
 * Subset validator — no PHP filter extension.
 */

#include <stddef.h>
#include <string.h>

typedef struct __string__ __string__;

extern __string__ *__string__init(long long size, const char *value);

static size_t fv_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *fv_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static int fv_local_char(unsigned char ch)
{
    if ((ch >= 'a' && ch <= 'z') || (ch >= 'A' && ch <= 'Z') || (ch >= '0' && ch <= '9')) {
        return 1;
    }
    return strchr(".!#$%&'*+/=?^_`{|}~-", (int) ch) != NULL;
}

static int fv_domain_char(unsigned char ch)
{
    if ((ch >= 'a' && ch <= 'z') || (ch >= 'A' && ch <= 'Z') || (ch >= '0' && ch <= '9')) {
        return 1;
    }

    return ch == '.' || ch == '-';
}

static int fv_part_ok(const char *p, size_t len, int (*ok)(unsigned char))
{
    for (size_t i = 0; i < len; i++) {
        if (!ok((unsigned char) p[i])) {
            return 0;
        }
    }

    return 1;
}

static int fv_email_subset(const char *s, size_t len)
{
    const char *at;
    size_t local_len;
    size_t domain_len;
    const char *domain;
    size_t i;
    int has_dot;

    if (len == 0 || len > 320) {
        return 0;
    }
    at = strchr(s, '@');
    if (at == NULL || strchr(at + 1, '@') != NULL) {
        return 0;
    }
    if (at == s || at == s + len - 1) {
        return 0;
    }
    local_len = (size_t) (at - s);
    domain = at + 1;
    domain_len = len - local_len - 1;
    if (local_len == 0 || domain_len == 0) {
        return 0;
    }
    has_dot = 0;
    for (i = 0; i < domain_len; i++) {
        if (domain[i] == '.') {
            has_dot = 1;
            break;
        }
    }
    if (!has_dot) {
        return 0;
    }
    if (!fv_part_ok(s, local_len, fv_local_char)) {
        return 0;
    }
    if (!fv_part_ok(domain, domain_len, fv_domain_char)) {
        return 0;
    }

    return 1;
}

/**
 * FILTER_VALIDATE_INT decimal string check (php-src logical_filters.c parity).
 * Rejects leading zeros except a lone "0" after optional sign.
 */
int __compiler_filter_int_string_is_valid(__string__ *input)
{
    const char *data;
    size_t len;
    size_t i;

    if (NULL == input) {
        return 0;
    }
    data = fv_strdata(input);
    len = fv_strlen(input);
    if (len == 0) {
        return 0;
    }
    i = 0;
    if (data[0] == '+' || data[0] == '-') {
        if (len == 1) {
            return 0;
        }
        i = 1;
    }
    if (len - i > 1 && data[i] == '0') {
        return 0;
    }
    for (; i < len; i++) {
        if (data[i] < '0' || data[i] > '9') {
            return 0;
        }
    }

    return 1;
}

/** Returns input on valid email; NULL on invalid (caller boxes as false). */
__string__ *__compiler_filter_validate_email(__string__ *input)
{
    const char *data;
    size_t len;

    if (NULL == input) {
        return NULL;
    }
    data = fv_strdata(input);
    len = fv_strlen(input);
    if (!fv_email_subset(data, len)) {
        return NULL;
    }

    return input;
}
