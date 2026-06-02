/*
 * levenshtein() runtime for VM/JIT/AOT (issue #2406, #4150).
 * Byte-oriented edit distance; no PHP internal wrappers.
 */

#include <stddef.h>
#include <stdint.h>
#include <stdlib.h>
#include <string.h>

static int64_t phpc_levenshtein_row(
    const unsigned char *s1,
    size_t len1,
    const unsigned char *s2,
    size_t len2,
    int64_t ins_cost,
    int64_t rep_cost,
    int64_t del_cost
)
{
    size_t row_len = len2 + 1;
    int64_t *prev = (int64_t *) malloc(row_len * sizeof(int64_t));
    int64_t *cur = (int64_t *) malloc(row_len * sizeof(int64_t));
    if (NULL == prev || NULL == cur) {
        free(prev);
        free(cur);
        return -1;
    }

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

    int64_t result = prev[len2];
    free(prev);
    free(cur);

    return result;
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

    int64_t dist = phpc_levenshtein_row(
        (const unsigned char *) s1,
        len1,
        (const unsigned char *) s2,
        len2,
        ins_cost,
        rep_cost,
        del_cost
    );
    if (dist < 0) {
        return -1;
    }

    return (int) dist;
}
