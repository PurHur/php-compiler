/*
 * unserialize() runtime for AOT/JIT (assoc arrays with scalar values; issue #1175).
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

#define PHPC_UNSER_MAX_LEN (8 * 1024 * 1024)
#define PHPC_UNSER_STR_CAP 4096

typedef struct {
    const char *pos;
    const char *end;
} phpc_unser_ctx;

typedef enum {
    PHPC_UNSER_NULL = 0,
    PHPC_UNSER_BOOL,
    PHPC_UNSER_LONG,
    PHPC_UNSER_STRING,
    PHPC_UNSER_ARRAY,
} phpc_unser_kind;

typedef struct {
    phpc_unser_kind kind;
    int boolVal;
    long long longVal;
    char strBuf[PHPC_UNSER_STR_CAP];
    __hashtable__ *ht;
} phpc_unser_item;

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

static int phpc_unser_parse_digits(phpc_unser_ctx *ctx, unsigned long *out)
{
    unsigned long n = 0;

    if (ctx->pos >= ctx->end || *ctx->pos < '0' || *ctx->pos > '9') {
        return 0;
    }
    while (ctx->pos < ctx->end && *ctx->pos >= '0' && *ctx->pos <= '9') {
        n = n * 10 + (unsigned long) (*ctx->pos - '0');
        ctx->pos++;
    }
    *out = n;

    return 1;
}

static int phpc_unser_parse_signed_long(phpc_unser_ctx *ctx, long long *out)
{
    int negative = 0;

    if (ctx->pos >= ctx->end) {
        return 0;
    }
    if ('-' == *ctx->pos) {
        negative = 1;
        ctx->pos++;
    }
    if (ctx->pos >= ctx->end || *ctx->pos < '0' || *ctx->pos > '9') {
        return 0;
    }
    *out = 0;
    while (ctx->pos < ctx->end && *ctx->pos >= '0' && *ctx->pos <= '9') {
        *out = *out * 10 + (*ctx->pos - '0');
        ctx->pos++;
    }
    if (negative) {
        *out = -*out;
    }

    return 1;
}

static int phpc_unser_parse_string_body(phpc_unser_ctx *ctx, size_t len, char *out, size_t out_cap)
{
    if (len + 1 > out_cap || ctx->pos + len > ctx->end) {
        return 0;
    }
    memcpy(out, ctx->pos, len);
    out[len] = '\0';
    ctx->pos += len;

    return 1;
}

static int phpc_unser_parse_item(phpc_unser_ctx *ctx, phpc_unser_item *item);

static int phpc_unser_ht_set(__hashtable__ *ht, __string__ *key, phpc_unser_item *item)
{
    switch (item->kind) {
        case PHPC_UNSER_NULL:
            __hashtable__setStringKeyString(ht, key, cstr_to_string(""));
            return 1;
        case PHPC_UNSER_BOOL:
            __hashtable__setStringKeyBool(ht, key, item->boolVal);
            return 1;
        case PHPC_UNSER_LONG:
            __hashtable__setStringKeyLong(ht, key, item->longVal);
            return 1;
        case PHPC_UNSER_STRING:
            __hashtable__setStringKeyString(ht, key, cstr_to_string(item->strBuf));
            return 1;
        case PHPC_UNSER_ARRAY:
            __hashtable__setStringKeyHashtable(ht, key, item->ht);
            return 1;
        default:
            return 0;
    }
}

static void phpc_unser_write_value(__value__ *out, phpc_unser_item *item)
{
    switch (item->kind) {
        case PHPC_UNSER_NULL:
            __value__writeNull(out);
            break;
        case PHPC_UNSER_BOOL:
            __value__writeLong(out, item->boolVal ? 1 : 0);
            break;
        case PHPC_UNSER_LONG:
            __value__writeLong(out, item->longVal);
            break;
        case PHPC_UNSER_STRING:
            __value__writeString(out, cstr_to_string(item->strBuf));
            break;
        case PHPC_UNSER_ARRAY:
            __value__writeHashtable(out, item->ht);
            break;
        default:
            __value__writeNull(out);
            break;
    }
}

static int phpc_unser_parse_string_item(phpc_unser_ctx *ctx, phpc_unser_item *item)
{
    unsigned long len = 0;

    if (!phpc_unser_expect(ctx, 's') || !phpc_unser_expect(ctx, ':')) {
        return 0;
    }
    if (!phpc_unser_parse_digits(ctx, &len) || !phpc_unser_expect(ctx, ':')) {
        return 0;
    }
    if (!phpc_unser_expect(ctx, '"')) {
        return 0;
    }
    if (!phpc_unser_parse_string_body(ctx, (size_t) len, item->strBuf, sizeof(item->strBuf))) {
        return 0;
    }
    if (!phpc_unser_expect(ctx, '"') || !phpc_unser_expect(ctx, ';')) {
        return 0;
    }
    item->kind = PHPC_UNSER_STRING;

    return 1;
}

static int phpc_unser_parse_array_item(phpc_unser_ctx *ctx, phpc_unser_item *item)
{
    unsigned long count = 0;
    __hashtable__ *ht = NULL;
    unsigned long i;

    if (!phpc_unser_expect(ctx, 'a') || !phpc_unser_expect(ctx, ':')) {
        return 0;
    }
    if (!phpc_unser_parse_digits(ctx, &count) || !phpc_unser_expect(ctx, ':')) {
        return 0;
    }
    if (!phpc_unser_expect(ctx, '{')) {
        return 0;
    }
    ht = __hashtable__alloc();
    for (i = 0; i < count; i++) {
        phpc_unser_item keyItem;
        phpc_unser_item valItem;
        __string__ *keyStr = NULL;

        memset(&keyItem, 0, sizeof(keyItem));
        memset(&valItem, 0, sizeof(valItem));
        if (!phpc_unser_parse_string_item(ctx, &keyItem)) {
            return 0;
        }
        if (!phpc_unser_parse_item(ctx, &valItem)) {
            return 0;
        }
        keyStr = cstr_to_string(keyItem.strBuf);
        if (!phpc_unser_ht_set(ht, keyStr, &valItem)) {
            return 0;
        }
    }
    if (!phpc_unser_expect(ctx, '}')) {
        return 0;
    }
    item->kind = PHPC_UNSER_ARRAY;
    item->ht = ht;

    return 1;
}

static int phpc_unser_parse_item(phpc_unser_ctx *ctx, phpc_unser_item *item)
{
    memset(item, 0, sizeof(*item));
    if (ctx->pos >= ctx->end) {
        return 0;
    }
    if ('N' == *ctx->pos) {
        if (!phpc_unser_expect(ctx, 'N') || !phpc_unser_expect(ctx, ';')) {
            return 0;
        }
        item->kind = PHPC_UNSER_NULL;

        return 1;
    }
    if ('b' == *ctx->pos) {
        if (!phpc_unser_expect(ctx, 'b') || !phpc_unser_expect(ctx, ':')) {
            return 0;
        }
        if (ctx->pos >= ctx->end || (*ctx->pos != '0' && *ctx->pos != '1')) {
            return 0;
        }
        item->kind = PHPC_UNSER_BOOL;
        item->boolVal = ('1' == *ctx->pos) ? 1 : 0;
        ctx->pos++;
        if (!phpc_unser_expect(ctx, ';')) {
            return 0;
        }

        return 1;
    }
    if ('i' == *ctx->pos) {
        if (!phpc_unser_expect(ctx, 'i') || !phpc_unser_expect(ctx, ':')) {
            return 0;
        }
        if (!phpc_unser_parse_signed_long(ctx, &item->longVal) || !phpc_unser_expect(ctx, ';')) {
            return 0;
        }
        item->kind = PHPC_UNSER_LONG;

        return 1;
    }
    if ('s' == *ctx->pos) {
        return phpc_unser_parse_string_item(ctx, item);
    }
    if ('a' == *ctx->pos) {
        return phpc_unser_parse_array_item(ctx, item);
    }

    return 0;
}

void __compiler_unserialize(__string__ *payload, __value__ *out)
{
    phpc_unser_ctx ctx;
    phpc_unser_item item;
    const char *body;
    size_t len;

    __value__writeNull(out);
    if (NULL == payload) {
        return;
    }
    body = phpc_string_data(payload);
    len = phpc_string_len(payload);
    if (0 == len || len > PHPC_UNSER_MAX_LEN) {
        return;
    }
    ctx.pos = body;
    ctx.end = body + len;
    if (!phpc_unser_parse_item(&ctx, &item)) {
        __value__writeNull(out);

        return;
    }
    phpc_unser_write_value(out, &item);
}
