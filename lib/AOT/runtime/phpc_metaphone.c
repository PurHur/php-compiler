/*
 * metaphone() runtime for VM/JIT/AOT (issue #2423).
 * PHP-compatible Metaphone (traditional=1); no PHP internal_* wrappers.
 */

#include <ctype.h>
#include <stddef.h>
#include <stdlib.h>
#include <string.h>

typedef struct __string__ __string__;

extern __string__ *__string__init(long long size, const char *value);

#define PHPC_METAPHONE_SH 'X'
#define PHPC_METAPHONE_TH '0'

static const char phpc_metaphone_codes[26] = {
    1, 16, 4, 16, 9, 2, 4, 16, 9, 2, 0, 2, 2, 2, 1, 4, 0, 2, 4, 4, 1, 0, 0, 0, 8, 0
};

static char phpc_metaphone_encode(char c)
{
    if (c >= 'a' && c <= 'z') {
        c = (char) (c - 32);
    }
    if (c >= 'A' && c <= 'Z') {
        return phpc_metaphone_codes[(unsigned char) c - 'A'];
    }

    return 0;
}

static int phpc_metaphone_isvowel(char c)
{
    return 0 != (phpc_metaphone_encode(c) & 1);
}

static int phpc_metaphone_nochange(char c)
{
    return 0 != (phpc_metaphone_encode(c) & 2);
}

static int phpc_metaphone_affecth(char c)
{
    return 0 != (phpc_metaphone_encode(c) & 4);
}

static int phpc_metaphone_makesoft(char c)
{
    return 0 != (phpc_metaphone_encode(c) & 8);
}

static int phpc_metaphone_noghtof(char c)
{
    return 0 != (phpc_metaphone_encode(c) & 16);
}

static char phpc_metaphone_upper(unsigned char c)
{
    if (c >= 'a' && c <= 'z') {
        return (char) (c - 32);
    }

    return (char) c;
}

static char phpc_metaphone_lookahead(const unsigned char *word, size_t w_idx, size_t how_far)
{
    size_t idx;
    for (idx = 0; word[w_idx + idx] != '\0' && idx < how_far; ++idx) {
    }

    return (char) word[w_idx + idx];
}

static int phpc_metaphone_isbreak(unsigned char c)
{
    return !isalpha((int) c);
}

static void phpc_metaphone_run(
    const unsigned char *word,
    size_t word_len,
    long max_phonemes,
    char *phoned,
    size_t *p_idx,
    size_t max_buffer_len
)
{
    size_t w_idx = 0;
    char curr_letter;
    const int traditional = 1;

    if (NULL == word) {
        word = (const unsigned char *) "";
        word_len = 0;
    }

    for (; !isalpha((int) (curr_letter = (char) word[w_idx])); ++w_idx) {
        if ('\0' == curr_letter) {
            phoned[*p_idx] = '\0';

            return;
        }
    }

    curr_letter = phpc_metaphone_upper((unsigned char) word[w_idx]);

    switch (curr_letter) {
        case 'A':
            if ('E' == phpc_metaphone_upper((unsigned char) word[w_idx + 1])) {
                if (*p_idx < max_buffer_len) {
                    phoned[(*p_idx)++] = 'E';
                }
                w_idx += 2;
            } else {
                if (*p_idx < max_buffer_len) {
                    phoned[(*p_idx)++] = 'A';
                }
                ++w_idx;
            }
            break;
        case 'G':
        case 'K':
        case 'P':
            if ('N' == phpc_metaphone_upper((unsigned char) word[w_idx + 1])) {
                if (*p_idx < max_buffer_len) {
                    phoned[(*p_idx)++] = 'N';
                }
                w_idx += 2;
            }
            break;
        case 'W': {
            char next_letter = phpc_metaphone_upper((unsigned char) word[w_idx + 1]);
            if ('R' == next_letter) {
                if (*p_idx < max_buffer_len) {
                    phoned[(*p_idx)++] = 'R';
                }
                w_idx += 2;
            } else if ('H' == next_letter || phpc_metaphone_isvowel(next_letter)) {
                if (*p_idx < max_buffer_len) {
                    phoned[(*p_idx)++] = 'W';
                }
                w_idx += 2;
            }
            break;
        }
        case 'X':
            if (*p_idx < max_buffer_len) {
                phoned[(*p_idx)++] = 'S';
            }
            ++w_idx;
            break;
        case 'E':
        case 'I':
        case 'O':
        case 'U':
            if (*p_idx < max_buffer_len) {
                phoned[(*p_idx)++] = curr_letter;
            }
            ++w_idx;
            break;
        default:
            break;
    }

    for (; (curr_letter = (char) word[w_idx]) != '\0'
         && (0 == max_phonemes || *p_idx < (size_t) max_phonemes);
         ++w_idx) {
        unsigned short skip_letter = 0;
        char prev_letter;
        char next_letter;
        char after_next_letter;

        if (!isalpha((int) (unsigned char) curr_letter)) {
            continue;
        }

        curr_letter = phpc_metaphone_upper((unsigned char) curr_letter);
        prev_letter = w_idx >= 1 ? phpc_metaphone_upper((unsigned char) word[w_idx - 1]) : '\0';

        if (curr_letter == prev_letter && 'C' != curr_letter) {
            continue;
        }

        switch (curr_letter) {
            case 'B':
                if ('M' != prev_letter && *p_idx < max_buffer_len) {
                    phoned[(*p_idx)++] = 'B';
                }
                break;
            case 'C':
                next_letter = phpc_metaphone_upper((unsigned char) word[w_idx + 1]);
                if (phpc_metaphone_makesoft(next_letter)) {
                    if ('I' == next_letter && 'A' == phpc_metaphone_upper((unsigned char) word[w_idx + 2])) {
                        if (*p_idx < max_buffer_len) {
                            phoned[(*p_idx)++] = PHPC_METAPHONE_SH;
                        }
                    } else if ('S' != prev_letter && *p_idx < max_buffer_len) {
                        phoned[(*p_idx)++] = 'S';
                    }
                } else if ('H' == next_letter) {
                    if ((!traditional) && ('S' == prev_letter || 'R' == phpc_metaphone_upper((unsigned char) word[w_idx + 2]))) {
                        if (*p_idx < max_buffer_len) {
                            phoned[(*p_idx)++] = 'K';
                        }
                    } else if (*p_idx < max_buffer_len) {
                        phoned[(*p_idx)++] = PHPC_METAPHONE_SH;
                    }
                    ++skip_letter;
                } else if (*p_idx < max_buffer_len) {
                    phoned[(*p_idx)++] = 'K';
                }
                break;
            case 'D':
                if ('G' == (next_letter = phpc_metaphone_upper((unsigned char) word[w_idx + 1]))
                    && phpc_metaphone_makesoft(phpc_metaphone_upper((unsigned char) word[w_idx + 2]))) {
                    if (*p_idx < max_buffer_len) {
                        phoned[(*p_idx)++] = 'J';
                    }
                    ++skip_letter;
                } else if (*p_idx < max_buffer_len) {
                    phoned[(*p_idx)++] = 'T';
                }
                break;
            case 'G':
                next_letter = phpc_metaphone_upper((unsigned char) word[w_idx + 1]);
                if ('H' == next_letter) {
                    if (!(phpc_metaphone_noghtof(w_idx >= 3 ? phpc_metaphone_upper((unsigned char) word[w_idx - 3]) : '\0')
                          || (w_idx >= 4 && 'H' == phpc_metaphone_upper((unsigned char) word[w_idx - 4])))) {
                        if (*p_idx < max_buffer_len) {
                            phoned[(*p_idx)++] = 'F';
                        }
                        ++skip_letter;
                    }
                } else if ('N' == next_letter) {
                    after_next_letter = phpc_metaphone_upper((unsigned char) word[w_idx + 2]);
                    if (phpc_metaphone_isbreak((unsigned char) after_next_letter)
                        || ('E' == after_next_letter
                            && 'D' == phpc_metaphone_upper((unsigned char) phpc_metaphone_lookahead(word, w_idx, 3)))) {
                    } else if (*p_idx < max_buffer_len) {
                        phoned[(*p_idx)++] = 'K';
                    }
                } else if (phpc_metaphone_makesoft(next_letter) && 'G' != prev_letter) {
                    if (*p_idx < max_buffer_len) {
                        phoned[(*p_idx)++] = 'J';
                    }
                } else if (*p_idx < max_buffer_len) {
                    phoned[(*p_idx)++] = 'K';
                }
                break;
            case 'H':
                if (phpc_metaphone_isvowel(phpc_metaphone_upper((unsigned char) word[w_idx + 1]))
                    && !phpc_metaphone_affecth(prev_letter)
                    && *p_idx < max_buffer_len) {
                    phoned[(*p_idx)++] = 'H';
                }
                break;
            case 'K':
                if ('C' != prev_letter && *p_idx < max_buffer_len) {
                    phoned[(*p_idx)++] = 'K';
                }
                break;
            case 'P':
                if ('H' == phpc_metaphone_upper((unsigned char) word[w_idx + 1])) {
                    if (*p_idx < max_buffer_len) {
                        phoned[(*p_idx)++] = 'F';
                    }
                } else if (*p_idx < max_buffer_len) {
                    phoned[(*p_idx)++] = 'P';
                }
                break;
            case 'Q':
                if (*p_idx < max_buffer_len) {
                    phoned[(*p_idx)++] = 'K';
                }
                break;
            case 'S':
                next_letter = phpc_metaphone_upper((unsigned char) word[w_idx + 1]);
                if ('I' == next_letter
                    && (('O' == (after_next_letter = phpc_metaphone_upper((unsigned char) word[w_idx + 2])))
                        || 'A' == after_next_letter)) {
                    if (*p_idx < max_buffer_len) {
                        phoned[(*p_idx)++] = PHPC_METAPHONE_SH;
                    }
                } else if ('H' == next_letter) {
                    if (*p_idx < max_buffer_len) {
                        phoned[(*p_idx)++] = PHPC_METAPHONE_SH;
                    }
                    ++skip_letter;
                } else if ((!traditional)
                           && 'C' == next_letter
                           && 'H' == phpc_metaphone_upper((unsigned char) phpc_metaphone_lookahead(word, w_idx, 2))
                           && 'W' == phpc_metaphone_upper((unsigned char) phpc_metaphone_lookahead(word, w_idx, 3))) {
                    if (*p_idx < max_buffer_len) {
                        phoned[(*p_idx)++] = PHPC_METAPHONE_SH;
                    }
                    skip_letter = 2;
                } else if (*p_idx < max_buffer_len) {
                    phoned[(*p_idx)++] = 'S';
                }
                break;
            case 'T':
                next_letter = phpc_metaphone_upper((unsigned char) word[w_idx + 1]);
                if ('I' == next_letter
                    && (('O' == (after_next_letter = phpc_metaphone_upper((unsigned char) word[w_idx + 2])))
                        || 'A' == after_next_letter)) {
                    if (*p_idx < max_buffer_len) {
                        phoned[(*p_idx)++] = PHPC_METAPHONE_SH;
                    }
                } else if ('H' == next_letter) {
                    if (*p_idx < max_buffer_len) {
                        phoned[(*p_idx)++] = PHPC_METAPHONE_TH;
                    }
                    ++skip_letter;
                } else if (!('C' == next_letter && 'H' == phpc_metaphone_upper((unsigned char) word[w_idx + 2]))
                           && *p_idx < max_buffer_len) {
                    phoned[(*p_idx)++] = 'T';
                }
                break;
            case 'V':
                if (*p_idx < max_buffer_len) {
                    phoned[(*p_idx)++] = 'F';
                }
                break;
            case 'W':
                if (phpc_metaphone_isvowel(phpc_metaphone_upper((unsigned char) word[w_idx + 1]))
                    && *p_idx < max_buffer_len) {
                    phoned[(*p_idx)++] = 'W';
                }
                break;
            case 'X':
                if (*p_idx + 1 < max_buffer_len) {
                    phoned[(*p_idx)++] = 'K';
                    phoned[(*p_idx)++] = 'S';
                }
                break;
            case 'Y':
                if (phpc_metaphone_isvowel(phpc_metaphone_upper((unsigned char) word[w_idx + 1]))
                    && *p_idx < max_buffer_len) {
                    phoned[(*p_idx)++] = 'Y';
                }
                break;
            case 'Z':
                if (*p_idx < max_buffer_len) {
                    phoned[(*p_idx)++] = 'S';
                }
                break;
            case 'F':
            case 'J':
            case 'L':
            case 'M':
            case 'N':
            case 'R':
                if (*p_idx < max_buffer_len) {
                    phoned[(*p_idx)++] = curr_letter;
                }
                break;
            default:
                break;
        }

        w_idx += skip_letter;
    }

    phoned[*p_idx] = '\0';
}

__string__ *phpc_metaphone(const char *str, long max_phonemes)
{
    size_t word_len;
    size_t max_buffer_len;
    char *phoned;
    size_t p_idx = 0;

    if (NULL == str) {
        str = "";
    }
    word_len = strlen(str);
    if (max_phonemes < 0) {
        max_phonemes = 0;
    }
    max_buffer_len = (0 == max_phonemes) ? word_len : (size_t) max_phonemes;
    if (max_buffer_len < word_len && 0 == max_phonemes) {
        max_buffer_len = word_len;
    }

    phoned = (char *) malloc(max_buffer_len + 2);
    if (NULL == phoned) {
        return __string__init(0, "");
    }

    phpc_metaphone_run((const unsigned char *) str, word_len, max_phonemes, phoned, &p_idx, max_buffer_len);
    {
        __string__ *result = __string__init((long long) p_idx, phoned);
        free(phoned);

        return result;
    }
}
