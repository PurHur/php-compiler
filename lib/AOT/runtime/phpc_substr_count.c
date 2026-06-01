/*
 * substr_count() runtime for JIT/AOT (issue #4105).
 * Mirrors php_substr_count() in ext/standard/string.c.
 */

#include <stddef.h>
#include <stdint.h>
#include <string.h>

static const char *phpc_memnstr(
    const char *haystack,
    const char *needle,
    size_t needle_len,
    const char *end
)
{
    if (0 == needle_len || haystack >= end) {
        return NULL;
    }

    size_t max = (size_t) (end - haystack);
    if (needle_len > max) {
        return NULL;
    }

    for (size_t i = 0; i <= max - needle_len; ++i) {
        if (0 == memcmp(haystack + i, needle, needle_len)) {
            return haystack + i;
        }
    }

    return NULL;
}

int64_t phpc_substr_count(
    const char *haystack,
    size_t haystack_len,
    const char *needle,
    size_t needle_len,
    int64_t offset,
    int64_t length,
    int length_is_null
)
{
    int64_t count = 0;
    const char *p;
    const char *endp;
    size_t search_len;

    if (NULL == haystack) {
        haystack = "";
        haystack_len = 0;
    }
    if (NULL == needle) {
        needle = "";
        needle_len = 0;
    }

    p = haystack;
    search_len = haystack_len;

    if (0 != offset) {
        if (offset < 0) {
            offset += (int64_t) haystack_len;
        }
        if (offset < 0 || (size_t) offset > haystack_len) {
            return -1;
        }
        p += (size_t) offset;
        search_len = haystack_len - (size_t) offset;
    }

    if (!length_is_null) {
        if (length < 0) {
            length += (int64_t) search_len;
        }
        if (length < 0 || (size_t) length > search_len) {
            return -2;
        }
        search_len = (size_t) length;
    }

    if (search_len < needle_len) {
        return 0;
    }

    endp = p + search_len;

    if (1 == needle_len) {
        const char cmp = needle[0];

        while (p < endp) {
            const char *found = memchr(p, cmp, (size_t) (endp - p));
            if (NULL == found) {
                break;
            }
            ++count;
            p = found + 1;
        }

        return count;
    }

    while (p <= endp - needle_len) {
        const char *found = phpc_memnstr(p, needle, needle_len, endp);
        if (NULL == found) {
            break;
        }
        ++count;
        p = found + needle_len;
    }

    return count;
}
