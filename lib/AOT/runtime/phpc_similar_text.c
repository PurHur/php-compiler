/*
 * similar_text() runtime for VM/JIT/AOT (issue #2445).
 * PHP-compatible Oliver algorithm; byte-oriented; max 255 bytes per string.
 */

#include <stddef.h>
#include <stdint.h>
#include <string.h>

#define PHPC_SIM_MAX_LEN 255

static void phpc_similar_str(
    const char *txt1,
    size_t len1,
    const char *txt2,
    size_t len2,
    size_t *pos1,
    size_t *pos2,
    size_t *max,
    size_t *count
)
{
    const char *p;
    const char *q;
    const char *end1 = txt1 + len1;
    const char *end2 = txt2 + len2;
    size_t l;

    *max = 0;
    *count = 0;
    for (p = txt1; p < end1; p++) {
        for (q = txt2; q < end2; q++) {
            for (l = 0; (p + l < end1) && (q + l < end2) && (p[l] == q[l]); l++) {
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

static size_t phpc_similar_char(const char *txt1, size_t len1, const char *txt2, size_t len2)
{
    size_t sum;
    size_t pos1 = 0;
    size_t pos2 = 0;
    size_t max = 0;
    size_t count = 0;

    phpc_similar_str(txt1, len1, txt2, len2, &pos1, &pos2, &max, &count);
    sum = max;
    if (sum) {
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

int phpc_similar_text(const char *s1, const char *s2)
{
    size_t len1;
    size_t len2;

    if (NULL == s1) {
        s1 = "";
    }
    if (NULL == s2) {
        s2 = "";
    }

    len1 = strlen(s1);
    len2 = strlen(s2);
    if (len1 > PHPC_SIM_MAX_LEN || len2 > PHPC_SIM_MAX_LEN) {
        return -1;
    }
    if (0 == len1 && 0 == len2) {
        return 0;
    }

    return (int) phpc_similar_char(s1, len1, s2, len2);
}
