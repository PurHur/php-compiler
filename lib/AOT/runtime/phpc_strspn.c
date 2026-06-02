/*
 * strspn()/strcspn() with optional offset and length (issue #3734).
 * Mirrors php_spn_common_handler in ext/standard/string.c.
 */

#include <stddef.h>
#include <stdint.h>

static int phpc_byte_in_set(unsigned char c, const unsigned char *mask, size_t mlen)
{
    for (size_t j = 0; j < mlen; ++j) {
        if (c == mask[j]) {
            return 1;
        }
    }

    return 0;
}

static void phpc_spn_normalize(
    size_t slen,
    int64_t *start,
    int64_t *len,
    int len_is_null
)
{
    int64_t remain = (int64_t) slen;
    int64_t s = *start;

    if (s < 0) {
        s += remain;
        if (s < 0) {
            s = 0;
        }
    } else if ((size_t) s > slen) {
        s = (int64_t) slen;
    }

    remain -= s;
    if (len_is_null) {
        *len = remain;
    } else {
        int64_t l = *len;
        if (l < 0) {
            l += remain;
            if (l < 0) {
                l = 0;
            }
        } else if ((size_t) l > (size_t) remain) {
            l = remain;
        }
        *len = l;
    }
    *start = s;
}

int64_t phpc_strspn_ex(
    const char *str,
    size_t slen,
    const char *mask,
    size_t mlen,
    int64_t start,
    int64_t len,
    int len_is_null,
    int is_strspn
)
{
    size_t i;
    int64_t count = 0;

    if (NULL == str) {
        str = "";
        slen = 0;
    }
    if (NULL == mask) {
        mask = "";
        mlen = 0;
    }
    phpc_spn_normalize(slen, &start, &len, len_is_null);
    if (len <= 0) {
        return 0;
    }
    if (0 == mlen) {
        return is_strspn ? 0 : len;
    }

    for (i = (size_t) start; i < (size_t) start + (size_t) len; ++i) {
        int in_set = phpc_byte_in_set((unsigned char) str[i], (const unsigned char *) mask, mlen);
        if (is_strspn) {
            if (!in_set) {
                break;
            }
        } else {
            if (in_set) {
                break;
            }
        }
        ++count;
    }

    return count;
}
