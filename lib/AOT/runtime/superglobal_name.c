/*
 * Superglobal name membership for AOT/JIT (issue #1056).
 * Returns 1 when $name is a PHP superglobal identifier, else 0.
 */

#include <stdint.h>
#include <string.h>

typedef struct __string__ __string__;

static size_t sn_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *sn_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static int sn_equals(__string__ *name, const char *literal)
{
    size_t name_len = sn_strlen(name);
    size_t lit_len = strlen(literal);
    if (name_len != lit_len) {
        return 0;
    }

    return 0 == strncmp(sn_strdata(name), literal, lit_len);
}

int64_t __compiler_is_superglobal_name(__string__ *name)
{
    if (sn_equals(name, "_GET")) {
        return 1;
    }
    if (sn_equals(name, "_POST")) {
        return 1;
    }
    if (sn_equals(name, "_SERVER")) {
        return 1;
    }
    if (sn_equals(name, "_REQUEST")) {
        return 1;
    }
    if (sn_equals(name, "_COOKIE")) {
        return 1;
    }
    if (sn_equals(name, "_ENV")) {
        return 1;
    }
    if (sn_equals(name, "_FILES")) {
        return 1;
    }
    if (sn_equals(name, "_SESSION")) {
        return 1;
    }

    return 0;
}
