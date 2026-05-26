/*
 * strnatcmp() runtime for VM/JIT/AOT (issue #2358).
 * Byte-oriented natural order; no PHP internal wrappers.
 */

#include <stddef.h>
#include <stdint.h>

static int is_digit(unsigned char c)
{
    return c >= '0' && c <= '9';
}

int strnatcmp(const char *a, const char *b)
{
    if (NULL == a) {
        a = "";
    }
    if (NULL == b) {
        b = "";
    }

    const unsigned char *pa = (const unsigned char *) a;
    const unsigned char *pb = (const unsigned char *) b;

    while (*pa != '\0' && *pb != '\0') {
        if (is_digit(*pa) && is_digit(*pb)) {
            while ('0' == *pa) {
                ++pa;
            }
            while ('0' == *pb) {
                ++pb;
            }
            const unsigned char *start_a = pa;
            const unsigned char *start_b = pb;
            while (is_digit(*pa)) {
                ++pa;
            }
            while (is_digit(*pb)) {
                ++pb;
            }
            size_t len_a = (size_t) (pa - start_a);
            size_t len_b = (size_t) (pb - start_b);
            if (0 == len_a && 0 == len_b) {
                continue;
            }
            if (len_a != len_b) {
                return len_a < len_b ? -1 : 1;
            }
            for (size_t k = 0; k < len_a; ++k) {
                if (start_a[k] != start_b[k]) {
                    return start_a[k] < start_b[k] ? -1 : 1;
                }
            }
            continue;
        }
        if (*pa != *pb) {
            return *pa < *pb ? -1 : 1;
        }
        ++pa;
        ++pb;
    }

    if (*pa == '\0' && *pb == '\0') {
        return 0;
    }
    if (*pa == '\0') {
        return -1;
    }

    return 1;
}
