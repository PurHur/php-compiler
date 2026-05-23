/*
 * function_exists() builtin registry for AOT/JIT (issue #1216).
 */

#include <stdint.h>
#include <string.h>

typedef struct __string__ __string__;

#include "builtin_function_names.inc"

static size_t fe_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *fe_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static int fe_name_equals(__string__ *name, const char *literal)
{
    size_t name_len = fe_strlen(name);
    size_t lit_len = strlen(literal);
    if (name_len != lit_len) {
        return 0;
    }

    return 0 == strncmp(fe_strdata(name), literal, lit_len);
}

static int fe_builtin_lookup(__string__ *name)
{
    size_t lo = 0;
    size_t hi = phpc_builtin_functions_count;
    while (lo < hi) {
        size_t mid = lo + (hi - lo) / 2;
        const char *entry = phpc_builtin_functions[mid];
        size_t name_len = fe_strlen(name);
        size_t entry_len = strlen(entry);
        int cmp;
        if (name_len < entry_len) {
            cmp = -1;
        } else if (name_len > entry_len) {
            cmp = 1;
        } else {
            cmp = strncmp(fe_strdata(name), entry, name_len);
        }
        if (0 == cmp) {
            return 1;
        }
        if (cmp < 0) {
            hi = mid;
        } else {
            lo = mid + 1;
        }
    }

    return 0;
}

int64_t __compiler_builtin_function_exists(__string__ *name)
{
    return fe_builtin_lookup(name) ? 1 : 0;
}
