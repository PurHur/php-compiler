/*
 * nl2br() runtime for JIT/AOT (issue #3783).
 * Mirrors php-src ext/standard/string.c PHP_FUNCTION(nl2br).
 */

#include <stddef.h>
#include <stdint.h>
#include <string.h>

typedef struct __string__ __string__;

extern __string__ *__string__init(long long size, const char *value);

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

#define PHPC_NL2BR_MAX 65536

__string__ *__compiler_nl2br(__string__ *input, int8_t use_xhtml)
{
    const char *src;
    const char *tmp;
    const char *end;
    const char *br;
    size_t br_len;
    size_t slen;
    size_t repl_cnt = 0;
    char buf[PHPC_NL2BR_MAX];
    char *target;

    if (NULL == input) {
        return __string__init(0, "");
    }

    slen = phpc_strlen(input);
    if (0 == slen) {
        return __string__init(0, "");
    }

    br = use_xhtml ? "<br />" : "<br>";
    br_len = use_xhtml ? 6 : 4;

    src = phpc_strdata(input);
    tmp = src;
    end = src + slen;

    while (tmp < end) {
        if (*tmp == '\r') {
            if (tmp + 1 < end && *(tmp + 1) == '\n') {
                tmp++;
            }
            repl_cnt++;
        } else if (*tmp == '\n') {
            if (tmp + 1 < end && *(tmp + 1) == '\r') {
                tmp++;
            }
            repl_cnt++;
        }
        tmp++;
    }

    if (0 == repl_cnt) {
        return __string__init((long long) slen, src);
    }

    if (slen + repl_cnt * br_len >= PHPC_NL2BR_MAX) {
        return __string__init((long long) slen, src);
    }

    target = buf;
    tmp = src;

    while (tmp < end) {
        if (*tmp == '\r' || *tmp == '\n') {
            memcpy(target, br, br_len);
            target += br_len;
            if ((tmp + 1 < end)
                && ((*tmp == '\r' && *(tmp + 1) == '\n')
                    || (*tmp == '\n' && *(tmp + 1) == '\r'))) {
                *target++ = *tmp++;
            }
            *target++ = *tmp;
        } else {
            *target++ = *tmp;
        }
        tmp++;
    }

    return __string__init((long long) (target - buf), buf);
}
