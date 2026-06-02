/*
 * strrpos() runtime for JIT/AOT (issue #4104).
 * Mirrors ext/standard/VmString::strrpos / php-src ext/standard/string.c.
 */

#include <stdint.h>
#include <string.h>

typedef struct __string__ __string__;

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

/** @return position, 0 when not found (JIT sentinel), -1 when offset out of range */
int64_t __compiler_strrpos(__string__ *haystack, __string__ *needle, int64_t offset)
{
    const char *hay;
    const char *ndl;
    size_t hay_len;
    size_t ndl_len;
    size_t min_start;
    size_t max_start;
    size_t i;
    int64_t last;

    if (NULL == haystack || NULL == needle) {
        return 0;
    }
    hay = phpc_strdata(haystack);
    ndl = phpc_strdata(needle);
    hay_len = phpc_strlen(haystack);
    ndl_len = phpc_strlen(needle);
    if (0 == ndl_len || hay_len < ndl_len) {
        return 0;
    }

    min_start = 0;
    max_start = hay_len - ndl_len;
    if (offset < 0) {
        int64_t suffix_end = (int64_t) hay_len + offset;
        if (suffix_end < 0) {
            return -1;
        }
        if ((size_t) suffix_end < max_start) {
            max_start = (size_t) suffix_end;
        }
    } else {
        if ((size_t) offset > max_start) {
            return 0;
        }
        min_start = (size_t) offset;
    }

    last = 0;
    for (i = min_start; i <= max_start; ++i) {
        if (0 == memcmp(hay + i, ndl, ndl_len)) {
            last = (int64_t) i;
        }
    }

    return last;
}
