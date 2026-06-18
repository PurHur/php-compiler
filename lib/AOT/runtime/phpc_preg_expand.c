/**
 * preg_replace() replacement-template expansion for AOT/JIT link (#9599).
 * Semantics mirror ext/standard/PregReplacementExpand.php (php-src ext/pcre/php_pcre.c).
 */
#include <ctype.h>
#include <stddef.h>
#include <stdint.h>
#include <string.h>

static size_t capture_group_text(
    int idx,
    const size_t *ovector,
    uint32_t count,
    const char *subj,
    char *out,
    size_t out_cap,
    size_t out_len)
{
    if (idx < 0 || (uint32_t)idx >= count) {
        return out_len;
    }
    size_t start = ovector[(size_t)idx * 2u];
    size_t end = ovector[(size_t)idx * 2u + 1u];
    if (start == (size_t)-1 || end == (size_t)-1) {
        return out_len;
    }
    size_t piece_len = end > start ? end - start : 0;
    if (piece_len > 0 && out_len + piece_len < out_cap) {
        memcpy(out + out_len, subj + start, piece_len);
        out_len += piece_len;
    }
    return out_len;
}

static size_t append_char(char *out, size_t out_cap, size_t out_len, char ch)
{
    if (out_len + 1u < out_cap) {
        out[out_len] = ch;
        return out_len + 1u;
    }
    return out_len;
}

size_t phpc_preg_expand_replacement(
    const char *repl,
    size_t repl_len,
    const size_t *ovector,
    uint32_t ovector_count,
    const char *subj,
    char *out,
    size_t out_cap)
{
    size_t out_len = 0;
    if (NULL == repl || NULL == out || out_cap == 0) {
        return 0;
    }
    if (NULL == ovector || NULL == subj) {
        if (repl_len < out_cap) {
            memcpy(out, repl, repl_len);
            return repl_len;
        }
        return 0;
    }

    for (size_t i = 0; i < repl_len; ++i) {
        char ch = repl[i];
        if ('\\' == ch && i + 1u < repl_len) {
            char next = repl[i + 1u];
            if (isdigit((unsigned char)next)) {
                size_t j = i + 1u;
                while (j < repl_len && isdigit((unsigned char)repl[j])) {
                    ++j;
                }
                int idx = 0;
                for (size_t k = i + 1u; k < j; ++k) {
                    idx = idx * 10 + (repl[k] - '0');
                }
                out_len = capture_group_text(idx, ovector, ovector_count, subj, out, out_cap, out_len);
                i = j - 1u;
                continue;
            }
            char expanded = next;
            if ('n' == next) {
                expanded = '\n';
            } else if ('r' == next) {
                expanded = '\r';
            } else if ('t' == next) {
                expanded = '\t';
            }
            out_len = append_char(out, out_cap, out_len, expanded);
            ++i;
            continue;
        }
        if ('$' == ch && i + 1u < repl_len) {
            if ('{' == repl[i + 1u]) {
                size_t j = i + 2u;
                while (j < repl_len && isdigit((unsigned char)repl[j])) {
                    ++j;
                }
                if (j < repl_len && '}' == repl[j]) {
                    int idx = 0;
                    for (size_t k = i + 2u; k < j; ++k) {
                        idx = idx * 10 + (repl[k] - '0');
                    }
                    out_len = capture_group_text(idx, ovector, ovector_count, subj, out, out_cap, out_len);
                    i = j;
                    continue;
                }
            } else if (isdigit((unsigned char)repl[i + 1u])) {
                size_t j = i + 1u;
                while (j < repl_len && isdigit((unsigned char)repl[j])) {
                    ++j;
                }
                int idx = 0;
                for (size_t k = i + 1u; k < j; ++k) {
                    idx = idx * 10 + (repl[k] - '0');
                }
                out_len = capture_group_text(idx, ovector, ovector_count, subj, out, out_cap, out_len);
                i = j - 1u;
                continue;
            }
        }
        out_len = append_char(out, out_cap, out_len, ch);
    }

    return out_len;
}
