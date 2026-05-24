/*
 * serialize() / unserialize() runtime for AOT/JIT (PHP format subset; issues #1174–#1175).
 */

#include <stdlib.h>
#include <stdint.h>
#include <string.h>
#include <stdio.h>

typedef struct __string__ __string__;
typedef struct __value__ __value__;

#define PHPC_HT_VALUES_OFFSET 32
#define PHPC_HT_STRKEYS_OFFSET 40
#define PHPC_NODE_KEY_OFFSET 8
#define PHPC_NODE_VALUE_OFFSET 16
#define PHPC_NODE_NEXT_OFFSET 32

typedef struct __strkey_node__ {
    char opaque[40];
} __strkey_node__;

typedef struct __hashtable__ {
    void *ref;
    size_t numElements;
    size_t nextFreeElement;
    size_t capacity;
    __value__ *values;
    __strkey_node__ *strKeys;
    void *objKeys;
} __hashtable__;

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

extern size_t __hashtable__getNumElements(__hashtable__ *ht);
extern int64_t __hashtable__readLongAt(__hashtable__ *ht, size_t index);
extern __string__ *__hashtable__readStringAt(__hashtable__ *ht, size_t index);
extern int __hashtable__offsetIsSet(__hashtable__ *ht, size_t index);
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
    char *data;
    size_t len;
    size_t cap;
} phpc_buf;

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

static int phpc_buf_init(phpc_buf *b, size_t initial)
{
    b->data = (char *) malloc(initial > 0 ? initial : 64);
    if (NULL == b->data) {
        return -1;
    }
    b->len = 0;
    b->cap = initial > 0 ? initial : 64;
    b->data[0] = '\0';

    return 0;
}

static void phpc_buf_free(phpc_buf *b)
{
    free(b->data);
    b->data = NULL;
    b->len = 0;
    b->cap = 0;
}

static int phpc_buf_grow(phpc_buf *b, size_t need)
{
    size_t new_cap;
    char *next;

    if (b->len + need + 1 <= b->cap) {
        return 0;
    }
    new_cap = b->cap > 0 ? b->cap : 64;
    while (new_cap < b->len + need + 1) {
        new_cap *= 2;
    }
    next = (char *) realloc(b->data, new_cap);
    if (NULL == next) {
        return -1;
    }
    b->data = next;
    b->cap = new_cap;

    return 0;
}

static int phpc_buf_append_raw(phpc_buf *b, const char *s, size_t n)
{
    if (0 != phpc_buf_grow(b, n)) {
        return -1;
    }
    memcpy(b->data + b->len, s, n);
    b->len += n;
    b->data[b->len] = '\0';

    return 0;
}

static int phpc_buf_append_cstr(phpc_buf *b, const char *s)
{
    return phpc_buf_append_raw(b, s, strlen(s));
}

static int phpc_buf_append_char(phpc_buf *b, char ch)
{
    return phpc_buf_append_raw(b, &ch, 1);
}

static int phpc_buf_append_ll(phpc_buf *b, long long v)
{
    char tmp[32];

    snprintf(tmp, sizeof(tmp), "%lld", (long long) v);

    return phpc_buf_append_cstr(b, tmp);
}

static int phpc_buf_append_double(phpc_buf *b, double v)
{
    char tmp[64];

    snprintf(tmp, sizeof(tmp), "%.17g", v);

    return phpc_buf_append_cstr(b, tmp);
}

static int phpc_value_bool(__value__ *v)
{
    if (NULL == v) {
        return 0;
    }

    return 0 != ((unsigned char *) v)[1];
}

static int phpc_serialize_value(phpc_buf *b, __value__ *v, int depth);

static int phpc_serialize_string_payload(phpc_buf *b, const char *data, size_t len)
{
    size_t i;

    if (0 != phpc_buf_append_cstr(b, "s:") || 0 != phpc_buf_append_ll(b, (long long) len) || 0 != phpc_buf_append_cstr(b, ":\"")) {
        return -1;
    }
    for (i = 0; i < len; i++) {
        char ch = data[i];

        if ('\\' == ch || '"' == ch) {
            if (0 != phpc_buf_append_char(b, '\\')) {
                return -1;
            }
        }
        if (0 != phpc_buf_append_char(b, ch)) {
            return -1;
        }
    }

    return phpc_buf_append_cstr(b, "\";");
}

static __strkey_node__ *phpc_ht_strkeys(__hashtable__ *ht)
{
    if (NULL == ht) {
        return NULL;
    }

    return *(__strkey_node__ **) ((char *) ht + PHPC_HT_STRKEYS_OFFSET);
}

static __string__ *phpc_node_key(__strkey_node__ *node)
{
    return *(__string__ **) ((char *) node + PHPC_NODE_KEY_OFFSET);
}

static __value__ *phpc_node_value(__strkey_node__ *node)
{
    return (__value__ *) ((char *) node + PHPC_NODE_VALUE_OFFSET);
}

static __strkey_node__ *phpc_node_next(__strkey_node__ *node)
{
    return *(__strkey_node__ **) ((char *) node + PHPC_NODE_NEXT_OFFSET);
}

static __value__ *phpc_ht_value_at(__hashtable__ *ht, size_t index)
{
    __value__ *values;

    if (NULL == ht) {
        return NULL;
    }
    values = *(__value__ **) ((char *) ht + PHPC_HT_VALUES_OFFSET);
    if (NULL == values) {
        return NULL;
    }

    return (__value__ *) ((char *) values + (index * PHPC_VALUE_SIZE));
}

static int phpc_serialize_hashtable(phpc_buf *b, __hashtable__ *ht, int depth)
{
    size_t count = 0;
    size_t i;
    size_t num = __hashtable__getNumElements(ht);

    if (depth >= PHPC_SER_MAX_DEPTH) {
        return -1;
    }

    count = num;

    if (0 != phpc_buf_append_cstr(b, "a:") || 0 != phpc_buf_append_ll(b, (long long) count) || 0 != phpc_buf_append_cstr(b, ":{")) {
        return -1;
    }

    for (i = 0; i < num; i++) {
        __string__ *str;

        if (0 != phpc_buf_append_cstr(b, "i:") || 0 != phpc_buf_append_ll(b, (long long) i) || 0 != phpc_buf_append_char(b, ';')) {
            return -1;
        }
        str = __hashtable__readStringAt(ht, i);
        if (NULL != str) {
            if (0 != phpc_serialize_string_payload(b, phpc_string_data(str), phpc_string_len(str))) {
                return -1;
            }
            continue;
        }
        if (0 != phpc_buf_append_cstr(b, "i:")) {
            return -1;
        }
        if (0 != phpc_buf_append_ll(b, __hashtable__readLongAt(ht, i))) {
            return -1;
        }
        if (0 != phpc_buf_append_char(b, ';')) {
            return -1;
        }
    }

    return phpc_buf_append_cstr(b, "}");
}

static int phpc_serialize_value(phpc_buf *b, __value__ *v, int depth)
{
    int kind;
    __string__ *str;
    __hashtable__ *ht;

    if (depth >= PHPC_SER_MAX_DEPTH) {
        return -1;
    }
    kind = phpc_value_type(v);
    switch (kind) {
        case PHPC_TYPE_NULL:
            return phpc_buf_append_cstr(b, "N;");
        case PHPC_TYPE_BOOL:
            return phpc_buf_append_cstr(b, phpc_value_bool(v) ? "b:1;" : "b:0;");
        case PHPC_TYPE_LONG:
            if (0 != phpc_buf_append_cstr(b, "i:")) {
                return -1;
            }
            if (0 != phpc_buf_append_ll(b, __value__readLong(v))) {
                return -1;
            }
            return phpc_buf_append_char(b, ';');
        case PHPC_TYPE_DOUBLE:
            if (0 != phpc_buf_append_cstr(b, "d:")) {
                return -1;
            }
            if (0 != phpc_buf_append_double(b, __value__readDouble(v))) {
                return -1;
            }
            return phpc_buf_append_char(b, ';');
        case PHPC_TYPE_STRING:
            str = __value__readString(v);
            return phpc_serialize_string_payload(b, phpc_string_data(str), phpc_string_len(str));
        case PHPC_TYPE_HASHTABLE:
            ht = __value__readHashtable(v);
            return phpc_serialize_hashtable(b, ht, depth + 1);
        default:
            return -1;
    }
}

__string__ *__compiler_serialize_value(__value__ *v)
{
    phpc_buf buf;
    __string__ *result;

    if (NULL == v) {
        return NULL;
    }
    if (0 != phpc_buf_init(&buf, 128)) {
        return NULL;
    }
    if (0 != phpc_serialize_value(&buf, v, 0)) {
        phpc_buf_free(&buf);

        return NULL;
    }
    if (buf.len > PHPC_SER_MAX_LEN) {
        phpc_buf_free(&buf);

        return NULL;
    }
    result = __string__init((long long) buf.len, buf.data);
    phpc_buf_free(&buf);

    return result;
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
