/*
 * soundex() runtime for VM/JIT/AOT (issue #2416).
 * ASCII Soundex (US) encoding; no PHP internal wrappers.
 */

#include <stddef.h>
#include <stdint.h>
#include <string.h>

typedef struct __string__ __string__;

extern __string__ *__string__init(long long size, const char *value);

static unsigned char phpc_upper(unsigned char c)
{
    if (c >= 'a' && c <= 'z') {
        return (unsigned char) (c - 32);
    }

    return c;
}

static unsigned char soundex_encode(unsigned char c)
{
    switch (c) {
        case 'B': case 'F': case 'P': case 'V':
            return 1;
        case 'C': case 'G': case 'J': case 'K': case 'Q': case 'S': case 'X': case 'Z':
            return 2;
        case 'D': case 'T':
            return 3;
        case 'L':
            return 4;
        case 'M': case 'N':
            return 5;
        case 'R':
            return 6;
        default:
            return 0;
    }
}

static __string__ *soundex_from_cstr(const char *input)
{
    size_t len;
    char out[5];
    size_t out_len = 0;
    unsigned char last_code = 0;
    size_t i;

    if (NULL == input) {
        input = "";
    }
    len = strlen(input);
    if (0 == len) {
        return __string__init(4, "0000");
    }

    out[out_len++] = (char) phpc_upper((unsigned char) input[0]);
    last_code = soundex_encode((unsigned char) out[0]);
    for (i = 1; i < len && out_len < 4; ++i) {
        unsigned char ch = phpc_upper((unsigned char) input[i]);
        unsigned char code = soundex_encode(ch);

        if (0 == code || code == last_code) {
            continue;
        }

        out[out_len++] = (char) ('0' + code);
        last_code = code;
    }

    while (out_len < 4) {
        out[out_len++] = '0';
    }
    out[4] = '\0';

    return __string__init(4, out);
}

/* JIT/AOT: argument is NUL-terminated string data (from __string__ value field). */
__string__ *__compiler_soundex(const char *input)
{
    return soundex_from_cstr(input);
}
