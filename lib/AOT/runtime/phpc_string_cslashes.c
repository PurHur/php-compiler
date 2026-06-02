/*
 * addcslashes(), stripcslashes(), substr_replace() runtime for JIT/AOT (issue #3356).
 * Mirrors ext/standard/VmString.php — php-src ext/standard/string.c parity.
 */

#include <stddef.h>
#include <stdint.h>
#include <stdlib.h>
#include <string.h>

typedef struct __string__ __string__;

extern __string__ *__string__init(long long size, const char *value);

static size_t sc_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *sc_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static int sc_is_hex(unsigned char c)
{
    return (c >= '0' && c <= '9') || (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F');
}

static unsigned char sc_hex_val(unsigned char c)
{
    if (c >= '0' && c <= '9') {
        return (unsigned char) (c - '0');
    }
    if (c >= 'a' && c <= 'f') {
        return (unsigned char) (c - 'a' + 10);
    }

    return (unsigned char) (c - 'A' + 10);
}

static char *sc_expand_charlist(const char *charlist, size_t charlist_len, size_t *out_len)
{
    char *buf = (char *) malloc(charlist_len + 1);
    if (NULL == buf) {
        *out_len = 0;

        return NULL;
    }
    size_t pos = 0;
    for (size_t i = 0; i < charlist_len; ++i) {
        unsigned char ch = (unsigned char) charlist[i];
        if ('\\' != ch || i + 1 >= charlist_len) {
            buf[pos++] = (char) ch;
            continue;
        }
        unsigned char next = (unsigned char) charlist[++i];
        switch (next) {
            case 'n':
                buf[pos++] = '\n';
                break;
            case 'r':
                buf[pos++] = '\r';
                break;
            case 'a':
                buf[pos++] = '\x07';
                break;
            case 't':
                buf[pos++] = '\t';
                break;
            case 'v':
                buf[pos++] = '\v';
                break;
            case 'b':
                buf[pos++] = '\x08';
                break;
            case 'f':
                buf[pos++] = '\f';
                break;
            case 'e':
                buf[pos++] = '\x1B';
                break;
            case 'x':
                if (i + 2 < charlist_len && sc_is_hex((unsigned char) charlist[i + 1])
                    && sc_is_hex((unsigned char) charlist[i + 2])) {
                    buf[pos++] = (char) ((sc_hex_val((unsigned char) charlist[i + 1]) << 4)
                        | sc_hex_val((unsigned char) charlist[i + 2]));
                    i += 2;
                } else {
                    buf[pos++] = 'x';
                }
                break;
            default:
                if (next >= '0' && next <= '7') {
                    char oct[4];
                    int digits = 0;
                    oct[digits++] = (char) next;
                    while (digits < 3 && i + 1 < charlist_len
                        && charlist[i + 1] >= '0' && charlist[i + 1] <= '7') {
                        oct[digits++] = charlist[++i];
                    }
                    oct[digits] = '\0';
                    buf[pos++] = (char) strtol(oct, NULL, 8);
                } else {
                    buf[pos++] = (char) next;
                }
                break;
        }
    }
    buf[pos] = '\0';
    *out_len = pos;

    return buf;
}

static void sc_build_mask(const char *expanded, size_t expanded_len, unsigned char mask[256])
{
    memset(mask, 0, 256);
    for (size_t i = 0; i < expanded_len; ++i) {
        unsigned char c = (unsigned char) expanded[i];
        if (i + 3 < expanded_len
            && '.' == expanded[i + 1]
            && '.' == expanded[i + 2]
            && (unsigned char) expanded[i + 3] >= c) {
            for (unsigned char ord = c; ord <= (unsigned char) expanded[i + 3]; ++ord) {
                mask[ord] = 1;
            }
            i += 3;
        } else {
            mask[c] = 1;
        }
    }
}

__string__ *__compiler_addcslashes(__string__ *subject, __string__ *charlist)
{
    size_t slen = sc_strlen(subject);
    const char *sdata = sc_strdata(subject);
    size_t clen = sc_strlen(charlist);
    const char *cdata = sc_strdata(charlist);

    size_t expanded_len = 0;
    char *expanded = sc_expand_charlist(cdata, clen, &expanded_len);
    if (NULL == expanded) {
        return __string__init(0, "");
    }

    unsigned char mask[256];
    sc_build_mask(expanded, expanded_len, mask);
    free(expanded);

    if (0 == slen) {
        return __string__init(0, "");
    }

    size_t out_cap = slen * 2;
    char *outbuf = (char *) malloc(out_cap + 1);
    if (NULL == outbuf) {
        return __string__init(0, "");
    }
    size_t out_len = 0;
    for (size_t i = 0; i < slen; ++i) {
        unsigned char ch = (unsigned char) sdata[i];
        if (mask[ch]) {
            if (out_len + 2 > out_cap) {
                out_cap = out_cap * 2 + 2;
                char *tmp = (char *) realloc(outbuf, out_cap + 1);
                if (NULL == tmp) {
                    free(outbuf);

                    return __string__init(0, "");
                }
                outbuf = tmp;
            }
            outbuf[out_len++] = '\\';
            outbuf[out_len++] = (char) ch;
        } else {
            if (out_len + 1 > out_cap) {
                out_cap = out_cap * 2 + 1;
                char *tmp = (char *) realloc(outbuf, out_cap + 1);
                if (NULL == tmp) {
                    free(outbuf);

                    return __string__init(0, "");
                }
                outbuf = tmp;
            }
            outbuf[out_len++] = (char) ch;
        }
    }
    outbuf[out_len] = '\0';
    __string__ *result = __string__init((long long) out_len, outbuf);
    free(outbuf);

    return result;
}

__string__ *__compiler_stripcslashes(__string__ *subject)
{
    size_t slen = sc_strlen(subject);
    const char *sdata = sc_strdata(subject);
    if (0 == slen) {
        return __string__init(0, "");
    }

    char *outbuf = (char *) malloc(slen + 1);
    if (NULL == outbuf) {
        return __string__init(0, "");
    }
    size_t out_len = 0;
    for (size_t i = 0; i < slen; ++i) {
        unsigned char ch = (unsigned char) sdata[i];
        if ('\\' != ch) {
            outbuf[out_len++] = (char) ch;
            continue;
        }
        if (i + 1 >= slen) {
            outbuf[out_len++] = '\\';
            break;
        }
        unsigned char next = (unsigned char) sdata[++i];
        switch (next) {
            case 'n':
                outbuf[out_len++] = '\n';
                break;
            case 'r':
                outbuf[out_len++] = '\r';
                break;
            case 'a':
                outbuf[out_len++] = '\x07';
                break;
            case 't':
                outbuf[out_len++] = '\t';
                break;
            case 'v':
                outbuf[out_len++] = '\v';
                break;
            case 'b':
                outbuf[out_len++] = '\x08';
                break;
            case 'f':
                outbuf[out_len++] = '\f';
                break;
            case 'e':
                outbuf[out_len++] = '\x1B';
                break;
            case 'x':
                if (i + 2 < slen && sc_is_hex((unsigned char) sdata[i + 1])
                    && sc_is_hex((unsigned char) sdata[i + 2])) {
                    outbuf[out_len++] = (char) ((sc_hex_val((unsigned char) sdata[i + 1]) << 4)
                        | sc_hex_val((unsigned char) sdata[i + 2]));
                    i += 2;
                } else {
                    outbuf[out_len++] = 'x';
                }
                break;
            default:
                if (next >= '0' && next <= '7') {
                    char oct[4];
                    int digits = 0;
                    oct[digits++] = (char) next;
                    while (digits < 3 && i + 1 < slen
                        && sdata[i + 1] >= '0' && sdata[i + 1] <= '7') {
                        oct[digits++] = sdata[++i];
                    }
                    oct[digits] = '\0';
                    outbuf[out_len++] = (char) strtol(oct, NULL, 8);
                } else {
                    outbuf[out_len++] = (char) next;
                }
                break;
        }
    }
    outbuf[out_len] = '\0';
    __string__ *result = __string__init((long long) out_len, outbuf);
    free(outbuf);

    return result;
}

__string__ *__compiler_substr_replace(
    __string__ *string,
    __string__ *replace,
    int64_t offset,
    int64_t length_arg,
    int32_t has_length
)
{
    size_t str_len = sc_strlen(string);
    const char *str_data = sc_strdata(string);
    size_t repl_len = sc_strlen(replace);
    const char *repl_data = sc_strdata(replace);

    if (offset < 0) {
        offset += (int64_t) str_len;
        if (offset < 0) {
            offset = 0;
        }
    } else if ((size_t) offset > str_len) {
        offset = (int64_t) str_len;
    }

    size_t remain = str_len - (size_t) offset;
    int64_t length = length_arg;
    if (!has_length) {
        length = (int64_t) remain;
    } else if (length < 0) {
        length += (int64_t) remain;
        if (length < 0) {
            length = 0;
        }
    } else if ((size_t) length > remain) {
        length = (int64_t) remain;
    }

    size_t out_len = (size_t) offset + repl_len + (str_len - (size_t) offset - (size_t) length);
    char *outbuf = (char *) malloc(out_len + 1);
    if (NULL == outbuf) {
        return __string__init(0, "");
    }
    size_t pos = 0;
    if ((size_t) offset > 0) {
        memcpy(outbuf + pos, str_data, (size_t) offset);
        pos += (size_t) offset;
    }
    if (repl_len > 0) {
        memcpy(outbuf + pos, repl_data, repl_len);
        pos += repl_len;
    }
    size_t tail_start = (size_t) offset + (size_t) length;
    if (tail_start < str_len) {
        size_t tail_len = str_len - tail_start;
        memcpy(outbuf + pos, str_data + tail_start, tail_len);
        pos += tail_len;
    }
    outbuf[pos] = '\0';
    __string__ *result = __string__init((long long) pos, outbuf);
    free(outbuf);

    return result;
}
