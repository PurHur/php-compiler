/*
 * unserialize() runtime for AOT/JIT (PHP format subset; issue #1175).
 */

#include <stdlib.h>
#include <stdint.h>
#include <string.h>
#include <stdio.h>

typedef struct __string__ __string__;
typedef struct __value__ __value__;

typedef struct __hashtable__ __hashtable__;

extern __string__ *__string__init(long long size, const char *value);
extern long long __value__readLong(__value__ *v);
extern double __value__readDouble(__value__ *v);
extern __string__ *__value__readString(__value__ *v);
extern __hashtable__ *__value__readHashtable(__value__ *v);
extern void __value__writeNull(__value__ *out);
extern void __value__writeLong(__value__ *out, long long v);
extern void __value__writeDouble(__value__ *out, double v);
extern void __value__writeString(__value__ *out, __string__ *str);
extern void __value__writeHashtable(__value__ *out, __hashtable__ *ht);

extern void __hashtable__setStringAt(__hashtable__ *ht, size_t index, __string__ *val);
extern void __hashtable__setLongAt(__hashtable__ *ht, size_t index, long long val);
extern void __hashtable__setDoubleAt(__hashtable__ *ht, size_t index, double val);
extern void __hashtable__setBoolAt(__hashtable__ *ht, size_t index, int val);
extern void __hashtable__setHashtableAt(__hashtable__ *ht, size_t index, __hashtable__ *child);
extern void __hashtable__setStringKeyString(__hashtable__ *ht, __string__ *key, __string__ *val);
extern void __hashtable__setStringKeyLong(__hashtable__ *ht, __string__ *key, long long val);
extern void __hashtable__setStringKeyBool(__hashtable__ *ht, __string__ *key, int val);
extern void __hashtable__setStringKeyHashtable(__hashtable__ *ht, __string__ *key, __hashtable__ *child);
extern __hashtable__ *__hashtable__alloc(void);

#define PHPC_TYPE_NULL 0
#define PHPC_TYPE_LONG 1
#define PHPC_TYPE_BOOL 2
#define PHPC_TYPE_DOUBLE 3
#define PHPC_TYPE_STRING 4
#define PHPC_TYPE_HASHTABLE 7

#define PHPC_VALUE_SIZE 16
#define PHPC_SER_MAX_DEPTH 32
#define PHPC_SER_MAX_LEN (4 * 1024 * 1024)

typedef struct {
    const char *pos;
    const char *end;
    int depth;
} phpc_ser_ctx;

static size_t phpc_string_len(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *phpc_string_data(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static __string__ *phpc_cstr_to_string(const char *cstr)
{
    return __string__init((long long) strlen(cstr), cstr);
}

static int phpc_value_type(__value__ *v)
{
    if (NULL == v) {
        return PHPC_TYPE_NULL;
    }

    return (int) (unsigned char) *((char *) v);
}

static int phpc_expect(phpc_ser_ctx *ctx, char ch)
{
    if (ctx->pos >= ctx->end || *ctx->pos != ch) {
        return 0;
    }
    ctx->pos++;

    return 1;
}

static int phpc_read_digits(phpc_ser_ctx *ctx, long long *out)
{
    long long val = 0;

    if (ctx->pos >= ctx->end || *ctx->pos < '0' || *ctx->pos > '9') {
        return 0;
    }
    while (ctx->pos < ctx->end && *ctx->pos >= '0' && *ctx->pos <= '9') {
        val = val * 10 + (*ctx->pos - '0');
        ctx->pos++;
    }
    *out = val;

    return 1;
}

static int phpc_parse_string(phpc_ser_ctx *ctx, char *out, size_t out_len)
{
    long long len;
    size_t i;

    if (!phpc_expect(ctx, 's') || !phpc_expect(ctx, ':') || !phpc_read_digits(ctx, &len) || !phpc_expect(ctx, ':')
        || !phpc_expect(ctx, '"')) {
        return 0;
    }
    if (len < 0 || (size_t) len >= out_len || ctx->pos + (size_t) len > ctx->end) {
        return 0;
    }
    for (i = 0; i < (size_t) len; i++) {
        char ch = ctx->pos[i];

        if ('\\' == ch) {
            i++;
            if (i >= (size_t) len) {
                return 0;
            }
            ch = ctx->pos[i];
        }
        out[i] = ch;
    }
    out[(size_t) len] = '\0';
    ctx->pos += (size_t) len;
    if (!phpc_expect(ctx, '"') || !phpc_expect(ctx, ';')) {
        return 0;
    }

    return 1;
}

static int phpc_parse_value(phpc_ser_ctx *ctx, __value__ *out);

static int phpc_parse_array(phpc_ser_ctx *ctx, __value__ *out)
{
    long long count;
    long long i;
    __hashtable__ *ht;

    if (ctx->depth >= PHPC_SER_MAX_DEPTH) {
        return 0;
    }
    ctx->depth++;
    if (!phpc_expect(ctx, 'a') || !phpc_expect(ctx, ':') || !phpc_read_digits(ctx, &count) || !phpc_expect(ctx, ':')
        || !phpc_expect(ctx, '{')) {
        ctx->depth--;

        return 0;
    }
    ht = __hashtable__alloc();
    if (NULL == ht) {
        ctx->depth--;

        return 0;
    }
    for (i = 0; i < count; i++) {
        char key_buf[4096];
        char val_opaque[PHPC_VALUE_SIZE];
        long long idx;
        __value__ *val_slot = (__value__ *) val_opaque;

        if (phpc_expect(ctx, 'i') && phpc_expect(ctx, ':') && phpc_read_digits(ctx, &idx) && phpc_expect(ctx, ';')) {
            if (!phpc_parse_value(ctx, val_slot)) {
                ctx->depth--;

                return 0;
            }
            switch (phpc_value_type(val_slot)) {
                case PHPC_TYPE_LONG:
                    __hashtable__setLongAt(ht, (size_t) idx, __value__readLong(val_slot));
                    break;
                case PHPC_TYPE_BOOL:
                    __hashtable__setBoolAt(ht, (size_t) idx, (int) __value__readLong(val_slot));
                    break;
                case PHPC_TYPE_DOUBLE:
                    __hashtable__setDoubleAt(ht, (size_t) idx, __value__readDouble(val_slot));
                    break;
                case PHPC_TYPE_STRING: {
                    __string__ *s = __value__readString(val_slot);
                    __hashtable__setStringAt(ht, (size_t) idx, s);
                    break;
                }
                case PHPC_TYPE_HASHTABLE:
                    __hashtable__setHashtableAt(ht, (size_t) idx, __value__readHashtable(val_slot));
                    break;
                default:
                    ctx->depth--;

                    return 0;
            }
            continue;
        }
        ctx->pos--;
        if (!phpc_parse_string(ctx, key_buf, sizeof(key_buf))) {
            ctx->depth--;

            return 0;
        }
        if (!phpc_parse_value(ctx, val_slot)) {
            ctx->depth--;

            return 0;
        }
        switch (phpc_value_type(val_slot)) {
            case PHPC_TYPE_LONG:
                __hashtable__setStringKeyLong(ht, phpc_cstr_to_string(key_buf), __value__readLong(val_slot));
                break;
            case PHPC_TYPE_BOOL:
                __hashtable__setStringKeyBool(ht, phpc_cstr_to_string(key_buf), (int) __value__readLong(val_slot));
                break;
            case PHPC_TYPE_STRING:
                __hashtable__setStringKeyString(ht, phpc_cstr_to_string(key_buf), __value__readString(val_slot));
                break;
            case PHPC_TYPE_HASHTABLE:
                __hashtable__setStringKeyHashtable(ht, phpc_cstr_to_string(key_buf), __value__readHashtable(val_slot));
                break;
            default:
                ctx->depth--;

                return 0;
        }
    }
    if (!phpc_expect(ctx, '}')) {
        ctx->depth--;

        return 0;
    }
    __value__writeHashtable(out, ht);
    ctx->depth--;

    return 1;
}

static int phpc_parse_value(phpc_ser_ctx *ctx, __value__ *out)
{
    long long num;
    char str_buf[4096];
    char dbl_buf[128];
    size_t dbl_i = 0;

    if (ctx->pos >= ctx->end) {
        return 0;
    }
    if (phpc_expect(ctx, 'N') && phpc_expect(ctx, ';')) {
        __value__writeNull(out);

        return 1;
    }
    if (phpc_expect(ctx, 'b') && phpc_expect(ctx, ':')) {
        if (!phpc_read_digits(ctx, &num) || !phpc_expect(ctx, ';')) {
            return 0;
        }
        __value__writeLong(out, num ? 1 : 0);

        return 1;
    }
    if (phpc_expect(ctx, 'i') && phpc_expect(ctx, ':')) {
        if (!phpc_read_digits(ctx, &num) || !phpc_expect(ctx, ';')) {
            return 0;
        }
        __value__writeLong(out, num);

        return 1;
    }
    if (phpc_expect(ctx, 'd') && phpc_expect(ctx, ':')) {
        while (ctx->pos < ctx->end && *ctx->pos != ';' && dbl_i + 1 < sizeof(dbl_buf)) {
            dbl_buf[dbl_i++] = *ctx->pos++;
        }
        dbl_buf[dbl_i] = '\0';
        if (!phpc_expect(ctx, ';')) {
            return 0;
        }
        __value__writeDouble(out, strtod(dbl_buf, NULL));

        return 1;
    }
    if (*ctx->pos == 's') {
        if (!phpc_parse_string(ctx, str_buf, sizeof(str_buf))) {
            return 0;
        }
        __value__writeString(out, phpc_cstr_to_string(str_buf));

        return 1;
    }
    if (*ctx->pos == 'a') {
        return phpc_parse_array(ctx, out);
    }

    return 0;
}

void __compiler_unserialize(__string__ *payload, __value__ *out)
{
    phpc_ser_ctx ctx;
    const char *body;
    size_t len;

    __value__writeNull(out);
    if (NULL == payload) {
        return;
    }
    body = phpc_string_data(payload);
    len = phpc_string_len(payload);
    if (0 == len || len > PHPC_SER_MAX_LEN) {
        return;
    }
    ctx.pos = body;
    ctx.end = body + len;
    ctx.depth = 0;
    if (!phpc_parse_value(&ctx, out)) {
        __value__writeNull(out);
    }
}
