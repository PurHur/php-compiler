/*
 * similar_text() runtime for VM/JIT/AOT (issue #2445).
 * PHP-compatible Oliver algorithm; no PHP internal wrappers.
 */

#include <stddef.h>
#include <stdint.h>
#include <string.h>

static void phpc_similar_str(
    const unsigned char *txt1,
    size_t len1,
    const unsigned char *txt2,
    size_t len2,
    size_t *pos1,
    size_t *pos2,
    size_t *max,
    size_t *count
)
{
    const unsigned char *end1 = txt1 + len1;
    const unsigned char *end2 = txt2 + len2;
    size_t l;

    *max = 0;
    *count = 0;
    for (const unsigned char *p = txt1; p < end1; ++p) {
        for (const unsigned char *q = txt2; q < end2; ++q) {
            for (l = 0; (p + l < end1) && (q + l < end2) && (p[l] == q[l]); ++l) {
            }
            if (l > *max) {
                *max = l;
                *count += 1;
                *pos1 = (size_t) (p - txt1);
                *pos2 = (size_t) (q - txt2);
            }
        }
    }
}

static size_t phpc_similar_char(
    const unsigned char *txt1,
    size_t len1,
    const unsigned char *txt2,
    size_t len2
)
{
    size_t sum;
    size_t pos1 = 0;
    size_t pos2 = 0;
    size_t max = 0;
    size_t count = 0;

    phpc_similar_str(txt1, len1, txt2, len2, &pos1, &pos2, &max, &count);
    if ((sum = max)) {
        if (pos1 && pos2 && count > 1) {
            sum += phpc_similar_char(txt1, pos1, txt2, pos2);
        }
        if ((pos1 + max < len1) && (pos2 + max < len2)) {
            sum += phpc_similar_char(
                txt1 + pos1 + max,
                len1 - pos1 - max,
                txt2 + pos2 + max,
                len2 - pos2 - max
            );
        }
    }

    return sum;
}

int phpc_similar_text(const char *s1, const char *s2, double *percent_out)
{
    size_t len1;
    size_t len2;
    size_t sim;

    if (NULL == s1) {
        s1 = "";
    }
    if (NULL == s2) {
        s2 = "";
    }

    len1 = strlen(s1);
    len2 = strlen(s2);
    if (0 == len1 + len2) {
        if (NULL != percent_out) {
            *percent_out = 0.0;
        }

        return 0;
    }

    sim = phpc_similar_char(
        (const unsigned char *) s1,
        len1,
        (const unsigned char *) s2,
        len2
    );
    if (NULL != percent_out) {
        *percent_out = (double) sim * 200.0 / (double) (len1 + len2);
    }

    return (int) sim;
}
