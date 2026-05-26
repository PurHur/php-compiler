/*
 * substr_compare() runtime for VM/JIT/AOT (issue #2400).
 * Byte-oriented; ASCII case fold when case_insensitive; no PHP internal wrappers.
 */

#include <stddef.h>
#include <stdint.h>
#include <string.h>

static unsigned char to_lower(unsigned char c)
{
    if (c >= 'A' && c <= 'Z') {
        return (unsigned char) (c + 32);
    }

    return c;
}

static int byte_strncmp(const unsigned char *a, const unsigned char *b, size_t n, int case_insensitive)
{
    for (size_t i = 0; i < n; ++i) {
        unsigned char ca = case_insensitive ? to_lower(a[i]) : a[i];
        unsigned char cb = case_insensitive ? to_lower(b[i]) : b[i];
        if (ca != cb) {
            return ca < cb ? -1 : 1;
        }
    }

    return 0;
}

int substr_compare(const char *haystack, const char *needle, int64_t offset, int64_t length_arg, int case_insensitive)
{
    if (NULL == haystack) {
        haystack = "";
    }
    if (NULL == needle) {
        needle = "";
    }

    size_t hay_len = strlen(haystack);
    size_t needle_len = strlen(needle);

    if (offset < 0) {
        offset += (int64_t) hay_len;
        if (offset < 0) {
            offset = 0;
        }
    }
    if ((size_t) offset > hay_len) {
        return -2;
    }

    const unsigned char *s1 = (const unsigned char *) haystack + (size_t) offset;
    size_t hay_remain = hay_len - (size_t) offset;
    size_t compare_remain = hay_remain;
    size_t cmp_len;

    if (length_arg >= 0) {
        if ((size_t) length_arg > hay_remain) {
            cmp_len = hay_remain;
        } else {
            cmp_len = (size_t) length_arg;
        }
        compare_remain = cmp_len;
    } else {
        cmp_len = needle_len > hay_remain ? hay_remain : needle_len;
    }

    int retval = byte_strncmp(s1, (const unsigned char *) needle, cmp_len, case_insensitive != 0);
    if (0 == retval && length_arg < 0 && compare_remain != needle_len) {
        return compare_remain < needle_len ? -1 : 1;
    }

    return retval;
}
