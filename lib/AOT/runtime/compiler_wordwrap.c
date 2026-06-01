/*
 * wordwrap() runtime for VM/JIT/AOT (issues #975, #3774).
 * Mirrors ext/standard/VmString.php / php-src ext/standard/string.c byte semantics.
 */

#include <stdint.h>
#include <stdlib.h>
#include <string.h>

#define PHPC_WORDWRAP_MAX 65536

typedef struct __string__ __string__;

extern __string__ *__string__init(long long size, const char *value);

static size_t compiler_wordwrap_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *compiler_wordwrap_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static int compiler_wordwrap_memcmp_n(const char *a, size_t a_off, const char *b, size_t b_off, size_t n)
{
    for (size_t i = 0; i < n; ++i) {
        if (a[a_off + i] != b[b_off + i]) {
            return 1;
        }
    }

    return 0;
}

static __string__ *__compiler_wordwrap_cut(const char *text, size_t len, int width, const char *brk, size_t brk_len)
{
    char *out;
    size_t out_len;
    size_t i;

    if (width < 1) {
        return __string__init((long long) len, text);
    }

    out_len = len + (len / (size_t) width) * brk_len;
    if (out_len >= PHPC_WORDWRAP_MAX) {
        out_len = PHPC_WORDWRAP_MAX - 1;
    }
    out = (char *) malloc(out_len + 1);
    if (NULL == out) {
        return __string__init(0, "");
    }
    out_len = 0;
    for (i = 0; i < len; i += (size_t) width) {
        size_t chunk;
        if (i > 0) {
            memcpy(out + out_len, brk, brk_len);
            out_len += brk_len;
        }
        chunk = (size_t) width;
        if (i + chunk > len) {
            chunk = len - i;
        }
        memcpy(out + out_len, text + i, chunk);
        out_len += chunk;
    }
    out[out_len] = '\0';
    {
        __string__ *result = __string__init((long long) out_len, out);
        free(out);

        return result;
    }
}

static __string__ *__compiler_wordwrap_fast(const char *text, size_t len, int width, char brk_byte)
{
    char *buf;
    size_t laststart;
    size_t lastspace;
    size_t current;

    if (len >= PHPC_WORDWRAP_MAX) {
        len = PHPC_WORDWRAP_MAX - 1;
    }
    buf = (char *) malloc(len + 1);
    if (NULL == buf) {
        return __string__init(0, "");
    }
    memcpy(buf, text, len);
    buf[len] = '\0';

    laststart = 0;
    lastspace = 0;
    for (current = 0; current < len; ++current) {
        char ch = buf[current];
        if (ch == brk_byte) {
            laststart = current + 1;
            lastspace = current + 1;
        } else if (ch == ' ') {
            if (current - laststart >= (size_t) width) {
                buf[current] = brk_byte;
                laststart = current + 1;
            }
            lastspace = current;
        } else if (current - laststart >= (size_t) width && laststart != lastspace) {
            buf[lastspace] = brk_byte;
            laststart = lastspace + 1;
        }
    }

    {
        __string__ *result = __string__init((long long) len, buf);
        free(buf);

        return result;
    }
}

static __string__ *__compiler_wordwrap_general(
    const char *text,
    size_t len,
    int width,
    const char *brk,
    size_t brk_len
)
{
    char *out;
    size_t out_cap;
    size_t out_len;
    size_t laststart;
    size_t lastspace;
    size_t current;

    out_cap = len + (len / (size_t) (width > 0 ? width : 1) + 2) * brk_len + brk_len;
    if (out_cap >= PHPC_WORDWRAP_MAX) {
        out_cap = PHPC_WORDWRAP_MAX - 1;
    }
    out = (char *) malloc(out_cap + 1);
    if (NULL == out) {
        return __string__init(0, "");
    }
    out_len = 0;
    laststart = 0;
    lastspace = 0;
    current = 0;

    while (current < len) {
        if (current + brk_len <= len
            && text[current] == brk[0]
            && 0 == compiler_wordwrap_memcmp_n(text, current, brk, 0, brk_len)) {
            size_t seg = current - laststart + brk_len;
            memcpy(out + out_len, text + laststart, seg);
            out_len += seg;
            current += brk_len;
            laststart = current;
            lastspace = current;
        } else if (text[current] == ' ') {
            if (current - laststart >= (size_t) width) {
                size_t seg = current - laststart;
                memcpy(out + out_len, text + laststart, seg);
                out_len += seg;
                memcpy(out + out_len, brk, brk_len);
                out_len += brk_len;
                laststart = current + 1;
            }
            lastspace = current;
            ++current;
        } else if (current - laststart >= (size_t) width && laststart < lastspace) {
            size_t seg = lastspace - laststart;
            memcpy(out + out_len, text + laststart, seg);
            out_len += seg;
            memcpy(out + out_len, brk, brk_len);
            out_len += brk_len;
            laststart = lastspace + 1;
            lastspace = laststart;
            ++current;
        } else {
            ++current;
        }
    }
    if (laststart < len) {
        size_t seg = len - laststart;
        memcpy(out + out_len, text + laststart, seg);
        out_len += seg;
    }
    out[out_len] = '\0';
    {
        __string__ *result = __string__init((long long) out_len, out);
        free(out);

        return result;
    }
}

__string__ *__compiler_wordwrap(__string__ *str, int64_t width, __string__ *brk, int8_t cut)
{
    size_t len;
    const char *text;
    size_t brk_len;
    const char *brk_data;

    if (NULL == str) {
        return __string__init(0, "");
    }
    len = compiler_wordwrap_strlen(str);
    if (0 == len) {
        return __string__init(0, "");
    }
    text = compiler_wordwrap_strdata(str);
    if (NULL == brk) {
        return __string__init((long long) len, text);
    }
    brk_len = compiler_wordwrap_strlen(brk);
    if (0 == brk_len) {
        return __string__init((long long) len, text);
    }
    brk_data = compiler_wordwrap_strdata(brk);
    if (0 == width && 0 != cut) {
        return __string__init((long long) len, text);
    }
    if (0 != cut) {
        return __compiler_wordwrap_cut(text, len, (int) width, brk_data, brk_len);
    }
    if (1 == brk_len) {
        return __compiler_wordwrap_fast(text, len, (int) width, brk_data[0]);
    }

    return __compiler_wordwrap_general(text, len, (int) width, brk_data, brk_len);
}
