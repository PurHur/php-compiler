/*
 * metaphone() runtime for VM/JIT/AOT (issue #2423).
 * PHP 8.2-compatible Metaphone (traditional=1); returns key via __string__init.
 *
 * Adapted from php-src ext/standard/metaphone.c (PHP license).
 */

#include <ctype.h>
#include <stddef.h>
#include <stdlib.h>
#include <string.h>

typedef struct __string__ __string__;

extern __string__ *__string__init(long long size, const char *value);

#define SH 'X'
#define TH '0'

static const char _codes[26] = {
    1, 16, 4, 16, 9, 2, 4, 16, 9, 2, 0, 2, 2, 2, 1, 4, 0, 2, 4, 4, 1, 0, 0, 0, 8, 0
};

#define ENCODE(c) (isalpha((unsigned char)(c)) ? _codes[((toupper((unsigned char)(c))) - 'A')] : 0)
#define isvowel(c) (ENCODE(c) & 1)
#define NOCHANGE(c) (ENCODE(c) & 2)
#define AFFECTH(c) (ENCODE(c) & 4)
#define MAKESOFT(c) (ENCODE(c) & 8)
#define NOGHTOF(c) (ENCODE(c) & 16)
#define Isbreak(c) (!isalpha((unsigned char)(c)))

typedef struct {
    char *buf;
    size_t cap;
    size_t len;
} phpc_metaphone_buf;

static int phpc_metaphone_grow(phpc_metaphone_buf *out, size_t extra)
{
    size_t need = out->len + extra + 1;
    if (need <= out->cap) {
        return 1;
    }
    size_t new_cap = out->cap ? out->cap : 16;
    while (new_cap < need) {
        new_cap *= 2;
    }
    char *next = (char *) realloc(out->buf, new_cap);
    if (NULL == next) {
        return 0;
    }
    out->buf = next;
    out->cap = new_cap;

    return 1;
}

static int phpc_metaphone_phonize(phpc_metaphone_buf *out, char c)
{
    if (!phpc_metaphone_grow(out, 1)) {
        return 0;
    }
    out->buf[out->len++] = c;
    out->buf[out->len] = '\0';

    return 1;
}

static char phpc_metaphone_lookahead(const unsigned char *word, size_t how_far)
{
    size_t idx = 0;
    while (word[idx] != '\0' && idx < how_far) {
        ++idx;
    }

    return (char) word[idx];
}

static void phpc_metaphone_core(const unsigned char *word, size_t word_len, long long max_phonemes, phpc_metaphone_buf *out)
{
    size_t w_idx = 0;
    size_t max_buffer_len;
    const int traditional = 1;

#define Curr_Letter (toupper((unsigned char) word[w_idx]))
#define Next_Letter (toupper((unsigned char) word[w_idx + 1]))
#define Look_Back_Letter(n) (w_idx >= (size_t) (n) ? toupper((unsigned char) word[w_idx - (size_t) (n)]) : '\0')
#define Prev_Letter (Look_Back_Letter(1))
#define After_Next_Letter (Next_Letter != '\0' ? toupper((unsigned char) word[w_idx + 2]) : '\0')
#define Look_Ahead_Letter(n) (toupper((unsigned char) phpc_metaphone_lookahead(word + w_idx, (size_t) (n))))
#define Phone_Len (out->len)
#define Phonize(c) phpc_metaphone_phonize(out, (c))

    if (NULL == word) {
        word = (const unsigned char *) "";
        word_len = 0;
    }

    if (max_phonemes == 0) {
        max_buffer_len = word_len > 0 ? word_len : 1;
    } else {
        max_buffer_len = (size_t) max_phonemes;
    }
    out->cap = max_buffer_len + 1;
    out->buf = (char *) malloc(out->cap);
    if (NULL == out->buf) {
        out->cap = 0;
        out->len = 0;

        return;
    }
    out->len = 0;
    out->buf[0] = '\0';

    for (; !isalpha((unsigned char) Curr_Letter); ++w_idx) {
        if (Curr_Letter == '\0') {
            return;
        }
    }

    switch (Curr_Letter) {
    case 'A':
        if (Next_Letter == 'E') {
            Phonize('E');
            w_idx += 2;
        } else {
            Phonize('A');
            ++w_idx;
        }
        break;
    case 'G':
    case 'K':
    case 'P':
        if (Next_Letter == 'N') {
            Phonize('N');
            w_idx += 2;
        }
        break;
    case 'W':
        if (Next_Letter == 'R') {
            Phonize(Next_Letter);
            w_idx += 2;
        } else if (Next_Letter == 'H' || isvowel(Next_Letter)) {
            Phonize('W');
            w_idx += 2;
        }
        break;
    case 'X':
        Phonize('S');
        ++w_idx;
        break;
    case 'E':
    case 'I':
    case 'O':
    case 'U':
        Phonize(Curr_Letter);
        ++w_idx;
        break;
    default:
        break;
    }

    for (; Curr_Letter != '\0' && (max_phonemes == 0 || Phone_Len < (size_t) max_phonemes); ++w_idx) {
        unsigned short skip_letter = 0;

        if (!isalpha((unsigned char) Curr_Letter)) {
            continue;
        }
        if (Curr_Letter == Prev_Letter && Curr_Letter != 'C') {
            continue;
        }

        switch (Curr_Letter) {
        case 'B':
            if (Prev_Letter != 'M') {
                Phonize('B');
            }
            break;
        case 'C':
            if (MAKESOFT(Next_Letter)) {
                if (After_Next_Letter == 'A' && Next_Letter == 'I') {
                    Phonize(SH);
                } else if (Prev_Letter == 'S') {
                } else {
                    Phonize('S');
                }
            } else if (Next_Letter == 'H') {
                if ((!traditional) && (After_Next_Letter == 'R' || Prev_Letter == 'S')) {
                    Phonize('K');
                } else {
                    Phonize(SH);
                }
                skip_letter = 1;
            } else {
                Phonize('K');
            }
            break;
        case 'D':
            if (Next_Letter == 'G' && MAKESOFT(After_Next_Letter)) {
                Phonize('J');
                skip_letter = 1;
            } else {
                Phonize('T');
            }
            break;
        case 'G':
            if (Next_Letter == 'H') {
                if (!(NOGHTOF(Look_Back_Letter(3)) || Look_Back_Letter(4) == 'H')) {
                    Phonize('F');
                    skip_letter = 1;
                }
            } else if (Next_Letter == 'N') {
                if (Isbreak(After_Next_Letter) || (After_Next_Letter == 'E' && Look_Ahead_Letter(3) == 'D')) {
                } else {
                    Phonize('K');
                }
            } else if (MAKESOFT(Next_Letter) && Prev_Letter != 'G') {
                Phonize('J');
            } else {
                Phonize('K');
            }
            break;
        case 'H':
            if (isvowel(Next_Letter) && !AFFECTH(Prev_Letter)) {
                Phonize('H');
            }
            break;
        case 'K':
            if (Prev_Letter != 'C') {
                Phonize('K');
            }
            break;
        case 'P':
            if (Next_Letter == 'H') {
                Phonize('F');
            } else {
                Phonize('P');
            }
            break;
        case 'Q':
            Phonize('K');
            break;
        case 'S':
            if (Next_Letter == 'I' && (After_Next_Letter == 'O' || After_Next_Letter == 'A')) {
                Phonize(SH);
            } else if (Next_Letter == 'H') {
                Phonize(SH);
                skip_letter = 1;
            } else if ((!traditional) && (Next_Letter == 'C' && Look_Ahead_Letter(2) == 'H' && Look_Ahead_Letter(3) == 'W')) {
                Phonize(SH);
                skip_letter = 2;
            } else {
                Phonize('S');
            }
            break;
        case 'T':
            if (Next_Letter == 'I' && (After_Next_Letter == 'O' || After_Next_Letter == 'A')) {
                Phonize(SH);
            } else if (Next_Letter == 'H') {
                Phonize(TH);
                skip_letter = 1;
            } else if (!(Next_Letter == 'C' && After_Next_Letter == 'H')) {
                Phonize('T');
            }
            break;
        case 'V':
            Phonize('F');
            break;
        case 'W':
            if (isvowel(Next_Letter)) {
                Phonize('W');
            }
            break;
        case 'X':
            Phonize('K');
            Phonize('S');
            break;
        case 'Y':
            if (isvowel(Next_Letter)) {
                Phonize('Y');
            }
            break;
        case 'Z':
            Phonize('S');
            break;
        case 'F':
        case 'J':
        case 'L':
        case 'M':
        case 'N':
        case 'R':
            Phonize(Curr_Letter);
            break;
        default:
            break;
        }

        w_idx += skip_letter;
    }

#undef Curr_Letter
#undef Next_Letter
#undef Look_Back_Letter
#undef Prev_Letter
#undef After_Next_Letter
#undef Look_Ahead_Letter
#undef Phone_Len
#undef Phonize
}

__string__ *phpc_metaphone(const char *str, long long max_phonemes)
{
    phpc_metaphone_buf out = {NULL, 0, 0};
    size_t word_len;
    __string__ *result;

    if (NULL == str) {
        str = "";
    }
    if (max_phonemes < 0) {
        max_phonemes = 0;
    }
    word_len = strlen(str);
    phpc_metaphone_core((const unsigned char *) str, word_len, max_phonemes, &out);
    if (NULL == out.buf) {
        return __string__init(0, "");
    }
    result = __string__init((long long) out.len, out.buf);
    free(out.buf);

    return result;
}
