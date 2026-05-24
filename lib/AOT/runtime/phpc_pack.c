/*
 * pack() runtime for AOT/JIT (issue #1375).
 * Subset aligned with PHP 8.2 pack(): Z, A, a, h, H, c, C, s, S, i, I, l, L,
 * n, N, v, V, q, Q, J, P, f, g, G, d, e, E, x, X, @.
 */

#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

typedef struct __string__ __string__;
typedef struct __value__ {
    char type;
    char value[8];
} __value__;

extern __string__ *__string__init(long long size, const char *value);
extern long long __value__readLong(__value__ *);
extern double __value__readDouble(__value__ *);
extern __string__ *__value__readString(__value__ *);
extern void __compiler_trigger_error(const char *message, size_t len, int level);

#define PACK_ERR_LEVEL 256

#if defined(__BYTE_ORDER__) && __BYTE_ORDER__ == __ORDER_BIG_ENDIAN__
# define PACK_MACHINE_LE 0
#else
# define PACK_MACHINE_LE 1
#endif

static size_t pack_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *pack_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static __string__ *pack_result(const char *buf, size_t len)
{
    return __string__init((long long) len, buf);
}

static __string__ *pack_fail(const char *msg)
{
    size_t len = strlen(msg);

    __compiler_trigger_error(msg, len, PACK_ERR_LEVEL);

    return pack_result("", 0);
}

static uint16_t pack_bswap16(uint16_t v)
{
    return (uint16_t) ((v >> 8) | (v << 8));
}

static uint32_t pack_bswap32(uint32_t v)
{
    return ((v & 0xFFU) << 24) | ((v & 0xFF00U) << 8) | ((v & 0xFF0000U) >> 8) | ((v & 0xFF000000U) >> 24);
}

static uint64_t pack_bswap64(uint64_t v)
{
    uint32_t lo = (uint32_t) (v & 0xFFFFFFFFU);
    uint32_t hi = (uint32_t) (v >> 32);

    return ((uint64_t) pack_bswap32(lo) << 32) | (uint64_t) pack_bswap32(hi);
}

static void pack_put_long(char *out, long long zl, size_t size, int little_endian)
{
    unsigned char bytes[8] = {0};
    size_t i;

    if (size > sizeof(bytes)) {
        size = sizeof(bytes);
    }
    memcpy(bytes, &zl, size);
    if ((little_endian != 0) != (PACK_MACHINE_LE != 0)) {
        if (8 == size) {
            uint64_t u;
            memcpy(&u, bytes, 8);
            u = pack_bswap64(u);
            memcpy(bytes, &u, 8);
        } else if (4 == size) {
            uint32_t u;
            memcpy(&u, bytes, 4);
            u = pack_bswap32(u);
            memcpy(bytes, &u, 4);
        } else if (2 == size) {
            uint16_t u;
            memcpy(&u, bytes, 2);
            u = pack_bswap16(u);
            memcpy(bytes, &u, 2);
        }
    }
    for (i = 0; i < size; i++) {
        out[i] = (char) bytes[i];
    }
}

static void pack_put_float(char *out, float f, int little_endian)
{
    union {
        float f;
        uint32_t i;
    } m;
    m.f = f;
    if ((little_endian != 0) != (PACK_MACHINE_LE != 0)) {
        m.i = pack_bswap32(m.i);
    }
    memcpy(out, &m.f, sizeof(float));
}

static void pack_put_double(char *out, double d, int little_endian)
{
    union {
        double d;
        uint64_t i;
    } m;
    m.d = d;
    if ((little_endian != 0) != (PACK_MACHINE_LE != 0)) {
        m.i = pack_bswap64(m.i);
    }
    memcpy(out, &m.d, sizeof(double));
}

static long long pack_arg_long(__value__ *v)
{
    return __value__readLong(v);
}

static double pack_arg_double(__value__ *v)
{
    return __value__readDouble(v);
}

static int pack_hex_nibble(char c)
{
    if (c >= '0' && c <= '9') {
        return c - '0';
    }
    if (c >= 'A' && c <= 'F') {
        return c - 'A' + 10;
    }
    if (c >= 'a' && c <= 'f') {
        return c - 'a' + 10;
    }

    return -1;
}

typedef struct {
    char code;
    int arg;
} pack_spec;

#define PACK_MAX_SPECS 256
#define PACK_MAX_OUT 65536

__string__ *__compiler_pack(__string__ *fmt, long long argc, __value__ *argv)
{
    const char *format;
    size_t formatlen;
    pack_spec specs[PACK_MAX_SPECS];
    size_t spec_count = 0;
    int currentarg = 0;
    size_t i;
    char *output = NULL;
    size_t outputsize = 0;
    size_t outputpos = 0;
    int num_args = (int) argc;

    if (NULL == fmt) {
        return pack_fail("pack(): Argument #1 ($format) must be of type string");
    }
    format = pack_strdata(fmt);
    formatlen = pack_strlen(fmt);
    if (0 == formatlen) {
        return pack_result("", 0);
    }

    for (i = 0; i < formatlen && spec_count < PACK_MAX_SPECS; spec_count++) {
        char code = format[i++];
        int arg = 1;

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

        switch (code) {
            case 'x':
            case 'X':
            case '@':
                if (arg < 0) {
                    arg = 1;
                }
                break;
            case 'a':
            case 'A':
            case 'Z':
            case 'h':
            case 'H':
                if (currentarg >= num_args) {
                    char mbuf[80];
                    (void) snprintf(mbuf, sizeof(mbuf), "pack(): Type %c: not enough arguments", code);
                    return pack_fail(mbuf);
                }
                if (arg < 0) {
                    __string__ *s = __value__readString(argv + currentarg);
                    arg = (int) pack_strlen(s);
                    if ('Z' == code) {
                        arg++;
                    }
                }
                currentarg++;
                break;
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
                if (arg < 0) {
                    arg = num_args - currentarg;
                }
                currentarg += arg;
                if (currentarg > num_args) {
                    char mbuf[80];
                    (void) snprintf(mbuf, sizeof(mbuf), "pack(): Type %c: too few arguments", code);
                    return pack_fail(mbuf);
                }
                break;
            default: {
                char mbuf[80];
                (void) snprintf(mbuf, sizeof(mbuf), "pack(): Type %c: unknown format code", code);
                return pack_fail(mbuf);
            }
        }

        specs[spec_count].code = code;
        specs[spec_count].arg = arg;
    }

    for (i = 0; i < spec_count; i++) {
        char code = specs[i].code;
        int arg = specs[i].arg;
        size_t inc = 0;

        switch (code) {
            case 'h':
            case 'H':
                inc = (size_t) ((arg / 2) + (arg % 2));
                break;
            case 'a':
            case 'A':
            case 'Z':
            case 'c':
            case 'C':
            case 'x':
                inc = (size_t) arg;
                break;
            case 's':
            case 'S':
            case 'n':
            case 'v':
                inc = (size_t) arg * 2;
                break;
            case 'i':
            case 'I':
                inc = (size_t) arg * sizeof(int);
                break;
            case 'l':
            case 'L':
            case 'N':
            case 'V':
                inc = (size_t) arg * 4;
                break;
            case 'q':
            case 'Q':
            case 'J':
            case 'P':
                inc = (size_t) arg * 8;
                break;
            case 'f':
            case 'g':
            case 'G':
                inc = (size_t) arg * sizeof(float);
                break;
            case 'd':
            case 'e':
            case 'E':
                inc = (size_t) arg * sizeof(double);
                break;
            case 'X':
                if ((size_t) arg > outputpos) {
                    outputpos = 0;
                } else {
                    outputpos -= (size_t) arg;
                }
                continue;
            case '@':
                outputpos = (size_t) (arg > 0 ? arg : 0);
                if (outputsize < outputpos) {
                    outputsize = outputpos;
                }
                continue;
            default:
                break;
        }
        if (inc > 0) {
            outputpos += inc;
        }
        if (outputsize < outputpos) {
            outputsize = outputpos;
        }
    }

    if (outputsize > PACK_MAX_OUT) {
        return pack_fail("pack(): integer overflow in format string");
    }
    output = (char *) calloc(outputsize > 0 ? outputsize : 1, 1);
    if (NULL == output) {
        return pack_fail("pack(): out of memory");
    }

    outputpos = 0;
    currentarg = 0;

    for (i = 0; i < spec_count; i++) {
        char code = specs[i].code;
        int arg = specs[i].arg;

        switch (code) {
            case 'a':
            case 'A':
            case 'Z': {
                size_t arg_cp = ('Z' != code) ? (size_t) arg : (arg > 0 ? (size_t) (arg - 1) : 0);
                __string__ *str = __value__readString(argv + currentarg++);
                size_t slen = pack_strlen(str);
                const char *sdata = pack_strdata(str);
                memset(output + outputpos, ('A' == code) ? ' ' : '\0', (size_t) arg);
                if (slen < arg_cp) {
                    memcpy(output + outputpos, sdata, slen);
                } else {
                    memcpy(output + outputpos, sdata, arg_cp);
                }
                outputpos += (size_t) arg;
                break;
            }
            case 'h':
            case 'H': {
                int nibbleshift = ('h' == code) ? 0 : 4;
                int first = 1;
                __string__ *str = __value__readString(argv + currentarg++);
                const char *v = pack_strdata(str);
                size_t slen = pack_strlen(str);
                size_t remain = (size_t) arg;

                if (remain > slen) {
                    remain = slen;
                }
                outputpos--;
                while (remain-- > 0) {
                    int n = pack_hex_nibble(*v++);
                    if (n < 0) {
                        n = 0;
                    }
                    if (first--) {
                        output[++outputpos] = 0;
                    } else {
                        first = 1;
                    }
                    output[outputpos] |= (char) (n << nibbleshift);
                    nibbleshift = (nibbleshift + 4) & 7;
                }
                outputpos++;
                break;
            }
            case 'c':
            case 'C':
                while (arg-- > 0) {
                    pack_put_long(output + outputpos, pack_arg_long(argv + currentarg++), 1, PACK_MACHINE_LE);
                    outputpos++;
                }
                break;
            case 's':
            case 'S':
            case 'n':
            case 'v': {
                int le = PACK_MACHINE_LE;
                if ('n' == code) {
                    le = 0;
                } else if ('v' == code) {
                    le = 1;
                }
                while (arg-- > 0) {
                    pack_put_long(output + outputpos, pack_arg_long(argv + currentarg++), 2, le);
                    outputpos += 2;
                }
                break;
            }
            case 'i':
            case 'I':
                while (arg-- > 0) {
                    pack_put_long(output + outputpos, pack_arg_long(argv + currentarg++), sizeof(int), PACK_MACHINE_LE);
                    outputpos += sizeof(int);
                }
                break;
            case 'l':
            case 'L':
            case 'N':
            case 'V': {
                int le = PACK_MACHINE_LE;
                if ('N' == code) {
                    le = 0;
                } else if ('V' == code) {
                    le = 1;
                }
                while (arg-- > 0) {
                    pack_put_long(output + outputpos, pack_arg_long(argv + currentarg++), 4, le);
                    outputpos += 4;
                }
                break;
            }
            case 'q':
            case 'Q':
            case 'J':
            case 'P': {
                int le = PACK_MACHINE_LE;
                if ('J' == code) {
                    le = 0;
                } else if ('P' == code) {
                    le = 1;
                }
                while (arg-- > 0) {
                    pack_put_long(output + outputpos, pack_arg_long(argv + currentarg++), 8, le);
                    outputpos += 8;
                }
                break;
            }
            case 'f':
                while (arg-- > 0) {
                    float fv = (float) pack_arg_double(argv + currentarg++);
                    memcpy(output + outputpos, &fv, sizeof(float));
                    outputpos += sizeof(float);
                }
                break;
            case 'g':
                while (arg-- > 0) {
                    pack_put_float(output + outputpos, (float) pack_arg_double(argv + currentarg++), 1);
                    outputpos += sizeof(float);
                }
                break;
            case 'G':
                while (arg-- > 0) {
                    pack_put_float(output + outputpos, (float) pack_arg_double(argv + currentarg++), 0);
                    outputpos += sizeof(float);
                }
                break;
            case 'd':
                while (arg-- > 0) {
                    double dv = pack_arg_double(argv + currentarg++);
                    memcpy(output + outputpos, &dv, sizeof(double));
                    outputpos += sizeof(double);
                }
                break;
            case 'e':
                while (arg-- > 0) {
                    pack_put_double(output + outputpos, pack_arg_double(argv + currentarg++), 1);
                    outputpos += sizeof(double);
                }
                break;
            case 'E':
                while (arg-- > 0) {
                    pack_put_double(output + outputpos, pack_arg_double(argv + currentarg++), 0);
                    outputpos += sizeof(double);
                }
                break;
            case 'x':
                memset(output + outputpos, '\0', (size_t) arg);
                outputpos += (size_t) arg;
                break;
            case 'X':
                if ((size_t) arg > outputpos) {
                    outputpos = 0;
                } else {
                    outputpos -= (size_t) arg;
                }
                break;
            case '@':
                if ((size_t) arg > outputpos) {
                    memset(output + outputpos, '\0', (size_t) arg - outputpos);
                }
                outputpos = (size_t) arg;
                break;
        }
    }

    {
        __string__ *result = pack_result(output, outputpos);
        free(output);

        return result;
    }
}
