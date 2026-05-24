/*
 * unserialize() runtime for AOT/JIT — assoc arrays with scalar values (issue #1175).
 * Mirrors the subset emitted by __compiler_serialize_* / PHP serialize() for scalars.
 */

#include <stdlib.h>
#include <stdint.h>
#include <string.h>

typedef struct __hashtable__ __hashtable__;
typedef struct __string__ __string__;
typedef struct __value__ __value__;

extern __hashtable__ *__hashtable__alloc(void);
extern void __hashtable__setStringKeyString(__hashtable__ *ht, __string__ *key, __string__ *val);
extern void __hashtable__setStringKeyLong(__hashtable__ *ht, __string__ *key, long long val);
extern void __hashtable__setStringKeyBool(__hashtable__ *ht, __string__ *key, int val);
extern void __hashtable__setStringKeyHashtable(__hashtable__ *ht, __string__ *key, __hashtable__ *child);
extern __string__ *__string__init(long long size, const char *value);
extern void __value__writeNull(__value__ *out);
extern void __value__writeLong(__value__ *out, long long v);
extern void __value__writeString(__value__ *out, __string__ *str);
extern void __value__writeHashtable(__value__ *out, __hashtable__ *ht);

#define PHPC_UNSER_MAX_DEPTH 32
#define PHPC_UNSER_MAX_LEN (8 * 1024 * 1024)

typedef struct {
    const char *pos;
    const char *end;
    int depth;
} phpc_unser_ctx;

static __string__ *cstr_to_string(const char *cstr)
{
    return __string__init((long long) strlen(cstr), cstr);
}

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

static int phpc_unser_expect(phpc_unser_ctx *ctx, char ch)
{
    if (ctx->pos >= ctx->end || *ctx->pos != ch) {
        return 0;
    }
    ctx->pos++;

    return 1;
}

static int phpc_unser_read_digits(phpc_unser_ctx *ctx, long long *out)
{
    long long n = 0;
    int seen = 0;

    if (ctx->pos >= ctx->end || *ctx->pos < '0' || *ctx->pos > '9') {
        return 0;
    }
    while (ctx->pos < ctx->end && *ctx->pos >= '0' && *ctx->pos <= '9') {
        seen = 1;
        n = n * 10 + (*ctx->pos - '0');
        ctx->pos++;
    }
    if (!seen) {
        return 0;
    }
    *out = n;

    return 1;
}

static int phpc_unser_read_quoted_string(phpc_unser_ctx *ctx, char *buf, size_t buf_len, size_t need_len)
{
    size_t i;

    if (need_len >= buf_len) {
        return 0;
    }
    if ((size_t) (ctx->end - ctx->pos) < need_len + 1) {
        return 0;
    }
    if (!phpc_unser_expect(ctx, '"')) {
        return 0;
    }
    for (i = 0; i < need_len; i++) {
        buf[i] = ctx->pos[i];
    }
    buf[need_len] = '\0';
    ctx->pos += need_len;
    if (!phpc_unser_expect(ctx, '"') || !phpc_unser_expect(ctx, ';')) {
        return 0;
    }

    return 1;
}

static int phpc_unser_parse_array(phpc_unser_ctx *ctx, __hashtable__ *ht);
static int phpc_unser_parse_value(phpc_unser_ctx *ctx, __value__ *out);

static int phpc_unser_parse_array_key_string(phpc_unser_ctx *ctx, char *buf, size_t buf_len)
{
    long long len = 0;

    if (!phpc_unser_expect(ctx, 's') || !phpc_unser_expect(ctx, ':')) {
        return 0;
    }
    if (!phpc_unser_read_digits(ctx, &len) || !phpc_unser_expect(ctx, ':')) {
        return 0;
    }

    return phpc_unser_read_quoted_string(ctx, buf, buf_len, (size_t) len);
}

static int phpc_unser_parse_array_value(phpc_unser_ctx *ctx, __hashtable__ *ht, const char *key)
{
    char val_buf[4096];
    long long n = 0;
    __string__ *k = cstr_to_string(key);

    if (ctx->pos >= ctx->end) {
        return 0;
    }
    if ('a' == *ctx->pos) {
        __hashtable__ *child = __hashtable__alloc();

        ctx->depth++;
        if (ctx->depth > PHPC_UNSER_MAX_DEPTH || !phpc_unser_parse_array(ctx, child)) {
            ctx->depth--;

            return 0;
        }
        ctx->depth--;
        __hashtable__setStringKeyHashtable(ht, k, child);

        return 1;
    }
    if ('s' == *ctx->pos) {
        if (!phpc_unser_expect(ctx, 's') || !phpc_unser_expect(ctx, ':')) {
            return 0;
        }
        if (!phpc_unser_read_digits(ctx, &n) || !phpc_unser_expect(ctx, ':')) {
            return 0;
        }
        if (!phpc_unser_read_quoted_string(ctx, val_buf, sizeof(val_buf), (size_t) n)) {
            return 0;
        }
        __hashtable__setStringKeyString(ht, k, __string__init(n, val_buf));

        return 1;
    }
    if ('i' == *ctx->pos) {
        if (!phpc_unser_expect(ctx, 'i') || !phpc_unser_expect(ctx, ':')) {
            return 0;
        }
        if (!phpc_unser_read_digits(ctx, &n) || !phpc_unser_expect(ctx, ';')) {
            return 0;
        }
        __hashtable__setStringKeyLong(ht, k, n);

        return 1;
    }
    if ('b' == *ctx->pos) {
        if (!phpc_unser_expect(ctx, 'b') || !phpc_unser_expect(ctx, ':')) {
            return 0;
        }
        if (!phpc_unser_read_digits(ctx, &n) || !phpc_unser_expect(ctx, ';')) {
            return 0;
        }
        __hashtable__setStringKeyBool(ht, k, (int) n);

        return 1;
    }
    if ('N' == *ctx->pos) {
        if (!phpc_unser_expect(ctx, 'N') || !phpc_unser_expect(ctx, ';')) {
            return 0;
        }
        __hashtable__setStringKeyString(ht, k, cstr_to_string(""));

        return 1;
    }

    return 0;
}

static int phpc_unser_parse_array(phpc_unser_ctx *ctx, __hashtable__ *ht)
{
    long long count = 0;
    long long i;
    char key_buf[4096];

    if (!phpc_unser_expect(ctx, 'a') || !phpc_unser_expect(ctx, ':')) {
        return 0;
    }
    if (!phpc_unser_read_digits(ctx, &count) || !phpc_unser_expect(ctx, ':')) {
        return 0;
    }
    if (!phpc_unser_expect(ctx, '{')) {
        return 0;
    }
    for (i = 0; i < count; i++) {
        if (!phpc_unser_parse_array_key_string(ctx, key_buf, sizeof(key_buf))) {
            return 0;
        }
        if (!phpc_unser_parse_array_value(ctx, ht, key_buf)) {
            return 0;
        }
    }
    if (!phpc_unser_expect(ctx, '}')) {
        return 0;
    }

    return 1;
}

static int phpc_unser_parse_value(phpc_unser_ctx *ctx, __value__ *out)
{
    char buf[4096];
    long long n = 0;

    if (ctx->pos >= ctx->end) {
        return 0;
    }
    if ('N' == *ctx->pos) {
        if (!phpc_unser_expect(ctx, 'N') || !phpc_unser_expect(ctx, ';')) {
            return 0;
        }
        __value__writeNull(out);

        return 1;
    }
    if ('b' == *ctx->pos) {
        if (!phpc_unser_expect(ctx, 'b') || !phpc_unser_expect(ctx, ':')) {
            return 0;
        }
        if (!phpc_unser_read_digits(ctx, &n) || !phpc_unser_expect(ctx, ';')) {
            return 0;
        }
        __value__writeLong(out, n ? 1 : 0);

        return 1;
    }
    if ('i' == *ctx->pos) {
        if (!phpc_unser_expect(ctx, 'i') || !phpc_unser_expect(ctx, ':')) {
            return 0;
        }
        if (!phpc_unser_read_digits(ctx, &n) || !phpc_unser_expect(ctx, ';')) {
            return 0;
        }
        __value__writeLong(out, n);

        return 1;
    }
    if ('s' == *ctx->pos) {
        if (!phpc_unser_expect(ctx, 's') || !phpc_unser_expect(ctx, ':')) {
            return 0;
        }
        if (!phpc_unser_read_digits(ctx, &n) || !phpc_unser_expect(ctx, ':')) {
            return 0;
        }
        if (!phpc_unser_read_quoted_string(ctx, buf, sizeof(buf), (size_t) n)) {
            return 0;
        }
        __value__writeString(out, __string__init(n, buf));

        return 1;
    }
    if ('a' == *ctx->pos) {
        __hashtable__ *ht;

        if (ctx->depth >= PHPC_UNSER_MAX_DEPTH) {
            return 0;
        }
        ht = __hashtable__alloc();
        ctx->depth++;
        if (!phpc_unser_parse_array(ctx, ht)) {
            ctx->depth--;

            return 0;
        }
        ctx->depth--;
        __value__writeHashtable(out, ht);

        return 1;
    }
    if ('O' == *ctx->pos || 'C' == *ctx->pos || 'r' == *ctx->pos || 'R' == *ctx->pos) {
        return 0;
    }

    return 0;
}

int64_t __compiler_unserialize(__string__ *data, __value__ *out)
{
    phpc_unser_ctx ctx;
    const char *body;
    size_t len;

    if (NULL == data || NULL == out) {
        return 0;
    }
    body = phpc_string_data(data);
    len = phpc_string_len(data);
    if (0 == len || len > PHPC_UNSER_MAX_LEN) {
        return 0;
    }
    ctx.pos = body;
    ctx.end = body + len;
    ctx.depth = 0;
    if (!phpc_unser_parse_value(&ctx, out)) {
        return 0;
    }
    if (ctx.pos != ctx.end) {
        return 0;
    }

    return 1;
}
