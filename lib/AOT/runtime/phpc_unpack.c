/*
 * unpack() runtime for AOT/JIT (issue #3188).
 * Subset aligned with PHP 8.2 unpack(): c, C, s, S, n, N, v, V, a, A, h, H, x, X, @.
 * php-src reference: ext/standard/pack.c — php_unpack()
 */

#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

typedef struct __string__ __string__;
typedef struct __value__ __value__;
typedef struct __hashtable__ __hashtable__;

extern __string__ *__string__init(long long size, const char *value);
extern __hashtable__ *__hashtable__alloc(void);
extern void __hashtable__setStringKeyLong(__hashtable__ *ht, __string__ *key, long long val);
extern void __hashtable__setStringKeyString(__hashtable__ *ht, __string__ *key, __string__ *val);
extern void __hashtable__setLongAt(__hashtable__ *ht, size_t index, long long val);
extern void __hashtable__setStringAt(__hashtable__ *ht, size_t index, __string__ *val);
extern void __value__writeHashtable(__value__ *out, __hashtable__ *ht);
extern void __compiler_trigger_error(const char *message, size_t len, int level);

#define UNPACK_ERR_LEVEL 256
#define UNPACK_MAX_SPECS 256
#define UNPACK_MAX_NAME 64

#if defined(__BYTE_ORDER__) && __BYTE_ORDER__ == __ORDER_BIG_ENDIAN__
# define UNPACK_MACHINE_LE 0
#else
# define UNPACK_MACHINE_LE 1
#endif

static size_t unpack_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *unpack_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static __string__ *unpack_key(const char *key)
{
    return __string__init((long long) strlen(key), key);
}

static __string__ *unpack_slice(const char *data, size_t len)
{
    return __string__init((long long) len, data);
}

static __hashtable__ *unpack_fail_ht(const char *msg)
{
    size_t len = strlen(msg);

    __compiler_trigger_error(msg, len, UNPACK_ERR_LEVEL);

    return __hashtable__alloc();
}

static uint16_t unpack_bswap16(uint16_t v)
{
    return (uint16_t) ((v >> 8) | (v << 8));
}

static uint32_t unpack_bswap32(uint32_t v)
{
    return ((v & 0xFFU) << 24) | ((v & 0xFF00U) << 8) | ((v & 0xFF0000U) >> 8) | ((v & 0xFF000000U) >> 24);
}

static uint64_t unpack_bswap64(uint64_t v)
{
    uint32_t lo = (uint32_t) (v & 0xFFFFFFFFU);
    uint32_t hi = (uint32_t) (v >> 32);

    return ((uint64_t) unpack_bswap32(lo) << 32) | (uint64_t) unpack_bswap32(hi);
}

static long long unpack_read_long(const unsigned char *bytes, size_t size, int little_endian, int is_signed)
{
    unsigned char buf[8] = {0};
    uint64_t u = 0;
    size_t i;

    if (size > sizeof(buf)) {
        size = sizeof(buf);
    }
    memcpy(buf, bytes, size);
    if ((little_endian != 0) != (UNPACK_MACHINE_LE != 0)) {
        if (8 == size) {
            memcpy(&u, buf, 8);
            u = unpack_bswap64(u);
            memcpy(buf, &u, 8);
        } else if (4 == size) {
            uint32_t v;
            memcpy(&v, buf, 4);
            v = unpack_bswap32(v);
            memcpy(buf, &v, 4);
        } else if (2 == size) {
            uint16_t v;
            memcpy(&v, buf, 2);
            v = unpack_bswap16(v);
            memcpy(buf, &v, 2);
        }
    }
    if (is_signed) {
        if (1 == size) {
            return (long long) (signed char) buf[0];
        }
        if (2 == size) {
            int16_t v;
            memcpy(&v, buf, 2);
            return (long long) v;
        }
        if (4 == size) {
            int32_t v;
            memcpy(&v, buf, 4);
            return (long long) v;
        }
        if (8 == size) {
            int64_t v;
            memcpy(&v, buf, 8);
            return (long long) v;
        }
    }
    memcpy(&u, buf, size);

    return (long long) u;
}

typedef struct {
    char code;
    int arg;
    char name[UNPACK_MAX_NAME];
    int has_name;
} unpack_spec;

static int unpack_is_code(char c)
{
    switch (c) {
        case 'a':
        case 'A':
        case 'Z':
        case 'h':
        case 'H':
        case 'c':
        case 'C':
        case 's':
        case 'S':
        case 'i':
        case 'I':
        case 'l':
        case 'L':
        case 'n':
        case 'N':
        case 'v':
        case 'V':
        case 'q':
        case 'Q':
        case 'J':
        case 'P':
        case 'f':
        case 'g':
        case 'G':
        case 'd':
        case 'e':
        case 'E':
        case 'x':
        case 'X':
        case '@':
            return 1;
        default:
            return 0;
    }
}

static void unpack_fail_out(__value__ *out, const char *msg)
{
    __value__writeHashtable(out, unpack_fail_ht(msg));
}

static void unpack_store_long(__hashtable__ *ht, const unpack_spec *spec, int *auto_idx, long long val)
{
    if (spec->has_name) {
        __hashtable__setStringKeyLong(ht, unpack_key(spec->name), val);
        return;
    }
    __hashtable__setLongAt(ht, (size_t) *auto_idx, val);
    (*auto_idx)++;
}

static void unpack_store_string(__hashtable__ *ht, const unpack_spec *spec, int *auto_idx, __string__ *val)
{
    if (spec->has_name) {
        __hashtable__setStringKeyString(ht, unpack_key(spec->name), val);
        return;
    }
    __hashtable__setStringAt(ht, (size_t) *auto_idx, val);
    (*auto_idx)++;
}

static int unpack_need_bytes(char code, int arg, size_t *need)
{
    switch (code) {
        case 'h':
        case 'H':
            *need = (size_t) ((arg / 2) + (arg % 2));
            return 1;
        case 'a':
        case 'A':
        case 'Z':
        case 'c':
        case 'C':
        case 'x':
            *need = (size_t) arg;
            return 1;
        case 's':
        case 'S':
        case 'n':
        case 'v':
            *need = (size_t) arg * 2;
            return 1;
        case 'i':
        case 'I':
            *need = (size_t) arg * sizeof(int);
            return 1;
        case 'l':
        case 'L':
        case 'N':
        case 'V':
            *need = (size_t) arg * 4;
            return 1;
        case 'q':
        case 'Q':
        case 'J':
        case 'P':
            *need = (size_t) arg * 8;
            return 1;
        case 'f':
        case 'g':
        case 'G':
            *need = (size_t) arg * sizeof(float);
            return 1;
        case 'd':
        case 'e':
        case 'E':
            *need = (size_t) arg * sizeof(double);
            return 1;
        case 'X':
        case '@':
            *need = 0;
            return 1;
        default:
            return 0;
    }
}

void __compiler_unpack(__string__ *fmt, __string__ *data, long long offset, __value__ *out)
{
    const char *format;
    const char *input;
    size_t formatlen;
    size_t inputlen;
    size_t pos;
    unpack_spec specs[UNPACK_MAX_SPECS];
    size_t spec_count = 0;
    size_t i;
    __hashtable__ *ht;
    int auto_idx = 1;

    if (NULL == fmt) {
        unpack_fail_out(out, "unpack(): Argument #1 ($format) must be of type string");

        return;
    }
    if (NULL == data) {
        unpack_fail_out(out, "unpack(): Argument #2 ($data) must be of type string");

        return;
    }
    format = unpack_strdata(fmt);
    formatlen = unpack_strlen(fmt);
    input = unpack_strdata(data);
    inputlen = unpack_strlen(data);
    if (offset < 0 || (size_t) offset > inputlen) {
        unpack_fail_out(out, "unpack(): Argument #3 ($offset) must be contained in argument #2 ($data)");

        return;
    }
    pos = (size_t) offset;

    for (i = 0; i < formatlen && spec_count < UNPACK_MAX_SPECS; spec_count++) {
        char code;
        int arg = 1;
        char name[UNPACK_MAX_NAME];
        size_t nlen = 0;

        if ('/' == format[i]) {
            i++;
        }
        if (i >= formatlen) {
            break;
        }
        code = format[i++];

        if (i < formatlen) {
            char c = format[i];
            if ('*' == c) {
                arg = -1;
                i++;
            } else if (c >= '0' && c <= '9') {
                arg = 0;
                while (i < formatlen && format[i] >= '0' && format[i] <= '9') {
                    arg = arg * 10 + (format[i] - '0');
                    i++;
                }
            }
        }

        if ('*' == arg || arg < 0) {
            char mbuf[80];
            (void) snprintf(mbuf, sizeof(mbuf), "unpack(): Type %c: '*' is not supported", code);
            unpack_fail_out(out, mbuf);

            return;
        }

        while (i < formatlen && '/' != format[i] && !unpack_is_code(format[i])) {
            if (nlen + 1 >= UNPACK_MAX_NAME) {
                unpack_fail_out(out, "unpack(): Argument #1 ($format) contains name longer than 64 characters");

                return;
            }
            name[nlen++] = format[i++];
        }
        name[nlen] = '\0';

        switch (code) {
            case 'x':
            case 'X':
            case '@':
                break;
            case 'a':
            case 'A':
            case 'Z':
            case 'h':
            case 'H':
            case 'c':
            case 'C':
            case 's':
            case 'S':
            case 'i':
            case 'I':
            case 'l':
            case 'L':
            case 'n':
            case 'N':
            case 'v':
            case 'V':
            case 'q':
            case 'Q':
            case 'J':
            case 'P':
            case 'f':
            case 'g':
            case 'G':
            case 'd':
            case 'e':
            case 'E':
                break;
            default: {
                char mbuf[80];
                (void) snprintf(mbuf, sizeof(mbuf), "unpack(): Type %c: unknown format code", code);
                unpack_fail_out(out, mbuf);

            return;
            }
        }

        specs[spec_count].code = code;
        specs[spec_count].arg = arg;
        specs[spec_count].has_name = (nlen > 0) ? 1 : 0;
        if (nlen > 0) {
            memcpy(specs[spec_count].name, name, nlen + 1);
        } else {
            specs[spec_count].name[0] = '\0';
        }
    }

    ht = __hashtable__alloc();

    for (i = 0; i < spec_count; i++) {
        char code = specs[i].code;
        int arg = specs[i].arg;
        size_t need = 0;
        int rep;

        if (!unpack_need_bytes(code, arg, &need)) {
            char mbuf[80];
            (void) snprintf(mbuf, sizeof(mbuf), "unpack(): Type %c: unknown format code", code);
            unpack_fail_out(out, mbuf);

            return;
        }

        switch (code) {
            case 'X':
                if ((size_t) arg > pos) {
                    pos = 0;
                } else {
                    pos -= (size_t) arg;
                }
                continue;
            case '@':
                pos = (size_t) (arg > 0 ? arg : 0);
                continue;
            case 'x':
                if (pos + (size_t) arg > inputlen) {
                    unpack_fail_out(out, "unpack(): Type x: not enough input, need more bytes");

                    return;
                }
                pos += (size_t) arg;
                continue;
            default:
                break;
        }

        if (pos + need > inputlen) {
            char mbuf[96];
            (void) snprintf(mbuf, sizeof(mbuf), "unpack(): Type %c: not enough input, need %d more bytes", code, (int) (need - (inputlen - pos)));
            unpack_fail_out(out, mbuf);

            return;
        }

        switch (code) {
            case 'a':
            case 'A':
            case 'Z': {
                size_t slen = ('Z' != code) ? (size_t) arg : (arg > 0 ? (size_t) (arg - 1) : 0);
                __string__ *str = unpack_slice(input + pos, slen);
                unpack_store_string(ht, &specs[i], &auto_idx, str);
                pos += (size_t) arg;
                break;
            }
            case 'h':
            case 'H': {
                char *buf = (char *) malloc((size_t) arg + 1);
                size_t n;

                if (NULL == buf) {
                    unpack_fail_out(out, "unpack(): out of memory");

                    return;
                }
                for (n = 0; n < (size_t) arg; n++) {
                    size_t bi = n / 2;
                    unsigned char b = (unsigned char) input[pos + bi];
                    int nibble;
                    if ('H' == code) {
                        nibble = (0 == (n & 1U)) ? ((b >> 4) & 0xF) : (b & 0xF);
                    } else {
                        nibble = (0 == (n & 1U)) ? (b & 0xF) : ((b >> 4) & 0xF);
                    }
                    buf[n] = (char) (nibble < 10 ? '0' + nibble : 'a' + nibble - 10);
                }
                buf[arg] = '\0';
                unpack_store_string(ht, &specs[i], &auto_idx, unpack_slice(buf, (size_t) arg));
                free(buf);
                pos += need;
                break;
            }
            case 'c':
                rep = arg;
                while (rep-- > 0) {
                    long long val = unpack_read_long((const unsigned char *) input + pos, 1, UNPACK_MACHINE_LE, 1);
                    unpack_store_long(ht, &specs[i], &auto_idx, val);
                    pos++;
                }
                break;
            case 'C':
                rep = arg;
                while (rep-- > 0) {
                    long long val = unpack_read_long((const unsigned char *) input + pos, 1, UNPACK_MACHINE_LE, 0);
                    unpack_store_long(ht, &specs[i], &auto_idx, val);
                    pos++;
                }
                break;
            case 's':
            case 'S':
            case 'n':
            case 'v': {
                int le = UNPACK_MACHINE_LE;
                int is_signed = ('s' == code) ? 1 : 0;
                if ('n' == code) {
                    le = 0;
                } else if ('v' == code) {
                    le = 1;
                }
                rep = arg;
                while (rep-- > 0) {
                    long long val = unpack_read_long((const unsigned char *) input + pos, 2, le, is_signed);
                    unpack_store_long(ht, &specs[i], &auto_idx, val);
                    pos += 2;
                }
                break;
            }
            case 'i':
            case 'I':
                rep = arg;
                while (rep-- > 0) {
                    long long val = unpack_read_long((const unsigned char *) input + pos, sizeof(int), UNPACK_MACHINE_LE, 'i' == code);
                    unpack_store_long(ht, &specs[i], &auto_idx, val);
                    pos += sizeof(int);
                }
                break;
            case 'l':
            case 'L':
            case 'N':
            case 'V': {
                int le = UNPACK_MACHINE_LE;
                int is_signed = ('l' == code || 'L' == code) ? 1 : 0;
                if ('N' == code) {
                    le = 0;
                } else if ('V' == code) {
                    le = 1;
                }
                rep = arg;
                while (rep-- > 0) {
                    long long val = unpack_read_long((const unsigned char *) input + pos, 4, le, is_signed);
                    unpack_store_long(ht, &specs[i], &auto_idx, val);
                    pos += 4;
                }
                break;
            }
            case 'q':
            case 'Q':
            case 'J':
            case 'P': {
                int le = UNPACK_MACHINE_LE;
                int is_signed = ('q' == code || 'Q' == code) ? 1 : 0;
                if ('J' == code) {
                    le = 0;
                } else if ('P' == code) {
                    le = 1;
                }
                rep = arg;
                while (rep-- > 0) {
                    long long val = unpack_read_long((const unsigned char *) input + pos, 8, le, is_signed);
                    unpack_store_long(ht, &specs[i], &auto_idx, val);
                    pos += 8;
                }
                break;
            }
            default:
                unpack_fail_out(out, "unpack(): format not supported in this compiler build");

                return;
        }
    }

    __value__writeHashtable(out, ht);
}
