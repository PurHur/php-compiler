/*
 * levenshtein() runtime for VM/JIT/AOT (issue #2406).
 * Byte-oriented edit distance; max 255 bytes per string; no PHP internal wrappers.
 */

#include <stddef.h>
#include <stdint.h>
#include <string.h>

#define PHPC_LEV_MAX_LEN 255

static int phpc_levenshtein_row(
    const unsigned char *s1,
    size_t len1,
    const unsigned char *s2,
    size_t len2,
    int64_t ins_cost,
    int64_t rep_cost,
    int64_t del_cost
)
{
    int64_t prev[PHPC_LEV_MAX_LEN + 1];
    int64_t cur[PHPC_LEV_MAX_LEN + 1];

    for (size_t j = 0; j <= len2; ++j) {
        prev[j] = (int64_t) j * ins_cost;
    }

    for (size_t i = 1; i <= len1; ++i) {
        cur[0] = (int64_t) i * del_cost;
        for (size_t j = 1; j <= len2; ++j) {
            int64_t subst = (s1[i - 1] == s2[j - 1]) ? 0 : rep_cost;
            int64_t del = cur[j - 1] + ins_cost;
            int64_t ins = prev[j] + del_cost;
            int64_t rep = prev[j - 1] + subst;
            int64_t best = del;
            if (ins < best) {
                best = ins;
            }
            if (rep < best) {
                best = rep;
            }
            cur[j] = best;
        }
        for (size_t j = 0; j <= len2; ++j) {
            prev[j] = cur[j];
        }
    }

    return (int) prev[len2];
}

int phpc_levenshtein(
    const char *s1,
    const char *s2,
    int64_t ins_cost,
    int64_t rep_cost,
    int64_t del_cost
)
{
    if (NULL == s1) {
        s1 = "";
    }
    if (NULL == s2) {
        s2 = "";
    }

    size_t len1 = strlen(s1);
    size_t len2 = strlen(s2);
    if (len1 > PHPC_LEV_MAX_LEN || len2 > PHPC_LEV_MAX_LEN) {
        return -1;
    }
    if (ins_cost < 1) {
        ins_cost = 1;
    }
    if (rep_cost < 1) {
        rep_cost = 1;
    }
    if (del_cost < 1) {
        del_cost = 1;
    }

    if (0 == len1) {
        return (int) ((int64_t) len2 * ins_cost);
    }
    if (0 == len2) {
        return (int) ((int64_t) len1 * del_cost);
    }

    return (int) phpc_levenshtein_row(
        (const unsigned char *) s1,
        len1,
        (const unsigned char *) s2,
        len2,
        ins_cost,
        rep_cost,
        del_cost
    );
}
