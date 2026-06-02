/*
 * var_export() runtime for AOT/JIT (issue #4474 repro helper).
 *
 * php-src reference: ext/standard/var.c — php_var_export_ex()
 *
 * Implemented subset:
 * - null, bool, int, double, string
 * - array/hashtable (int + string keys), scalar values
 *
 * Output format matches Zend's default var_export() formatting for arrays:
 *
 * array (
 *   1 => 4660,
 * )
 */

#include <stddef.h>
#include <stdint.h>
#include <stdlib.h>
#include <string.h>
#include <stdio.h>

typedef struct __string__ __string__;
typedef struct __hashtable__ __hashtable__;

typedef struct __value__ {
    int8_t type;
    int8_t value[8];
} __value__;

typedef struct __ref__ {
    int32_t refcount;
    int32_t typeinfo;
} __ref__;

typedef struct __strkey_node__ {
    __ref__ ref;
    __string__ *key;
    __value__ value;
    struct __strkey_node__ *next;
} __strkey_node__;

typedef struct __hashtable__ {
    __ref__ ref;
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
extern int __hashtable__offsetIsSet(__hashtable__ *ht, size_t index);

#define PHPC_TYPE_NULL 0
#define PHPC_TYPE_NATIVE_LONG 1
#define PHPC_TYPE_NATIVE_BOOL 2
#define PHPC_TYPE_NATIVE_DOUBLE 3
#define PHPC_TYPE_STRING 4
#define PHPC_TYPE_HASHTABLE 7

static int phpc_value_kind(const __value__ *v)
{
    return (int) (v->type & 0x7f);
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

typedef struct {
    char *buf;
    size_t len;
    size_t cap;
} ve_buf;

static void ve_ensure(ve_buf *b, size_t add)
{
    size_t need = b->len + add + 1;
    if (need <= b->cap) {
        return;
    }
    size_t new_cap = b->cap > 0 ? b->cap : 256;
    while (new_cap < need) {
        new_cap *= 2;
    }
    char *grown = (char *) realloc(b->buf, new_cap);
    if (NULL == grown) {
        /* OOM: keep existing buffer and truncate future writes. */
        return;
    }
    b->buf = grown;
    b->cap = new_cap;
}

static void ve_append_bytes(ve_buf *b, const char *s, size_t n)
{
    if (0 == n || NULL == s) {
        return;
    }
    ve_ensure(b, n);
    if (b->cap <= b->len + 1) {
        return;
    }
    if (n > b->cap - b->len - 1) {
        n = b->cap - b->len - 1;
    }
    memcpy(b->buf + b->len, s, n);
    b->len += n;
    b->buf[b->len] = '\0';
}

static void ve_append_cstr(ve_buf *b, const char *s)
{
    if (NULL == s) {
        return;
    }
    ve_append_bytes(b, s, strlen(s));
}

static void ve_append_char(ve_buf *b, char ch)
{
    ve_ensure(b, 1);
    if (b->cap <= b->len + 1) {
        return;
    }
    b->buf[b->len++] = ch;
    b->buf[b->len] = '\0';
}

static void ve_append_indent(ve_buf *b, int level)
{
    int i;
    for (i = 0; i < level; ++i) {
        ve_append_bytes(b, "  ", 2);
    }
}

static void ve_append_ll(ve_buf *b, long long v)
{
    char tmp[64];
    int n = snprintf(tmp, sizeof(tmp), "%lld", v);
    if (n > 0) {
        ve_append_bytes(b, tmp, (size_t) n);
    }
}

static void ve_append_double(ve_buf *b, double v)
{
    char tmp[128];
    int n = snprintf(tmp, sizeof(tmp), "%G", v);
    if (n > 0) {
        ve_append_bytes(b, tmp, (size_t) n);
    }
}

static void ve_append_quoted_string(ve_buf *b, __string__ *s)
{
    const char *data = phpc_string_data(s);
    size_t n = phpc_string_len(s);
    size_t i;

    ve_append_char(b, '\'');
    for (i = 0; i < n; ++i) {
        char ch = data[i];
        if ('\\' == ch || '\'' == ch) {
            ve_append_char(b, '\\');
        }
        ve_append_char(b, ch);
    }
    ve_append_char(b, '\'');
}

static void ve_export_value(ve_buf *b, __value__ *v, int level);

static void ve_export_array(ve_buf *b, __hashtable__ *ht, int level)
{
    size_t i;
    __strkey_node__ *node;

    ve_append_cstr(b, "array (\n");

    if (NULL != ht) {
        for (i = 0; i < ht->nextFreeElement; ++i) {
            if (PHPC_TYPE_NULL == phpc_value_kind(&ht->values[i])) {
                continue;
            }
            ve_append_indent(b, level + 1);
            ve_append_ll(b, (long long) i);
            ve_append_cstr(b, " => ");
            ve_export_value(b, &ht->values[i], level + 1);
            ve_append_cstr(b, ",\n");
        }
        for (node = ht->strKeys; NULL != node; node = node->next) {
            ve_append_indent(b, level + 1);
            ve_append_quoted_string(b, node->key);
            ve_append_cstr(b, " => ");
            ve_export_value(b, &node->value, level + 1);
            ve_append_cstr(b, ",\n");
        }
    }

    ve_append_indent(b, level);
    ve_append_cstr(b, ")");
}

static void ve_export_value(ve_buf *b, __value__ *v, int level)
{
    int kind;

    if (NULL == v) {
        ve_append_cstr(b, "NULL");
        return;
    }
    kind = phpc_value_kind(v);
    switch (kind) {
        case PHPC_TYPE_NULL:
            ve_append_cstr(b, "NULL");
            return;
        case PHPC_TYPE_NATIVE_BOOL:
            ve_append_cstr(b, __value__readLong(v) ? "true" : "false");
            return;
        case PHPC_TYPE_NATIVE_LONG:
            ve_append_ll(b, __value__readLong(v));
            return;
        case PHPC_TYPE_NATIVE_DOUBLE:
            ve_append_double(b, __value__readDouble(v));
            return;
        case PHPC_TYPE_STRING:
            ve_append_quoted_string(b, __value__readString(v));
            return;
        case PHPC_TYPE_HASHTABLE:
            ve_export_array(b, __value__readHashtable(v), level);
            return;
        default:
            ve_append_cstr(b, "NULL");
            return;
    }
}

__string__ *__compiler_var_export(__value__ *v)
{
    ve_buf b;

    b.buf = (char *) malloc(256);
    b.len = 0;
    b.cap = (NULL != b.buf) ? 256 : 0;
    if (NULL != b.buf) {
        b.buf[0] = '\0';
    }
    ve_export_value(&b, v, 0);

    if (NULL == b.buf) {
        return __string__init(0, "");
    }

    __string__ *out = __string__init((long long) b.len, b.buf);
    free(b.buf);

    return out;
}

