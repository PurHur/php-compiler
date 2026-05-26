/*
 * levenshtein() runtime for VM/JIT/AOT (issue #2406).
 * Byte-oriented edit distance; max string length 255; no PHP internal wrappers.
 */

#include <stddef.h>
#include <stdint.h>
#include <string.h>

#define LEVENSHTEIN_MAX_LENGTH 255

int levenshtein(const char *s1, const char *s2, int cost_ins, int cost_rep, int cost_del)
{
    if (NULL == s1) {
        s1 = "";
    }
    if (NULL == s2) {
        s2 = "";
    }

    size_t l1 = strlen(s1);
    size_t l2 = strlen(s2);

    if (l1 > LEVENSHTEIN_MAX_LENGTH || l2 > LEVENSHTEIN_MAX_LENGTH) {
        return -1;
    }
    if (cost_ins <= 0 || cost_rep <= 0 || cost_del <= 0) {
        return -1;
    }

    if (0 == l1) {
        return (int) (l2 * (size_t) cost_ins);
    }
    if (0 == l2) {
        return (int) (l1 * (size_t) cost_del);
    }

    int row0[LEVENSHTEIN_MAX_LENGTH + 1];
    int row1[LEVENSHTEIN_MAX_LENGTH + 1];

    for (size_t j = 0; j <= l2; ++j) {
        row0[j] = (int) (j * (size_t) cost_ins);
    }

    for (size_t i = 1; i <= l1; ++i) {
        row1[0] = (int) (i * (size_t) cost_del);
        for (size_t j = 1; j <= l2; ++j) {
            int cost = (s1[i - 1] == s2[j - 1]) ? 0 : cost_rep;
            int del = row0[j] + cost_del;
            int ins = row1[j - 1] + cost_ins;
            int rep = row0[j - 1] + cost;
            int min = del;
            if (ins < min) {
                min = ins;
            }
            if (rep < min) {
                min = rep;
            }
            row1[j] = min;
        }
        for (size_t j = 0; j <= l2; ++j) {
            row0[j] = row1[j];
        }
    }

    return row0[l2];
}
