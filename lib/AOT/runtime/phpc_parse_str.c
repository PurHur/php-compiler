/*
 * parse_str() runtime for JIT/AOT — form-encoded query parsing into __hashtable__.
 * Mirrors superglobals_refresh.c parse_delimited_pairs (issue #1367).
 */

#include <stdlib.h>
#include <string.h>

typedef struct __string__ __string__;
typedef struct __hashtable__ __hashtable__;

extern __hashtable__ *__hashtable__alloc(void);
extern __string__ *__string__init(long long size, const char *value);
extern void __hashtable__setStringKeyString(__hashtable__ *ht, __string__ *key, __string__ *val);
extern void __hashtable__setStringKeyHashtable(__hashtable__ *ht, __string__ *key, __hashtable__ *child);
extern void __hashtable__setStringAt(__hashtable__ *ht, size_t index, __string__ *val);
extern size_t __hashtable__getNumElements(__hashtable__ *ht);
extern __hashtable__ *__hashtable__readStringKeyHashtable(__hashtable__ *ht, __string__ *key);

#define PHPC_PARSE_MAX_KEY_PARTS 16

typedef struct {
    char *parts[PHPC_PARSE_MAX_KEY_PARTS];
    size_t count;
    int append_list;
} phpc_parsed_key;

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
    size_t len = strlen(cstr);

    return __string__init((long long) len, cstr);
}

static void phpc_set_string_key(__hashtable__ *ht, const char *key, const char *value)
{
    __string__ *k = phpc_cstr_to_string(key);
    __string__ *v = phpc_cstr_to_string(value);

    __hashtable__setStringKeyString(ht, k, v);
}

static int phpc_is_hex(char c)
{
    return (c >= '0' && c <= '9') || (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F');
}

static int phpc_hex_value(char c)
{
    if (c >= '0' && c <= '9') {
        return c - '0';
    }
    if (c >= 'a' && c <= 'f') {
        return c - 'a' + 10;
    }

    return c - 'A' + 10;
}

static void phpc_url_decode_inplace(char *s)
{
    char *w = s;

    for (char *r = s; '\0' != *r; r++) {
        if ('+' == *r) {
            *w++ = ' ';
        } else if ('%' == *r && phpc_is_hex(r[1]) && phpc_is_hex(r[2])) {
            *w++ = (char) (phpc_hex_value(r[1]) * 16 + phpc_hex_value(r[2]));
            r += 2;
        } else {
            *w++ = *r;
        }
    }
    *w = '\0';
}

static void phpc_free_parsed_key(phpc_parsed_key *pk)
{
    size_t i;

    for (i = 0; i < pk->count; i++) {
        free(pk->parts[i]);
        pk->parts[i] = NULL;
    }
    pk->count = 0;
    pk->append_list = 0;
}

static int phpc_parse_key_brackets(const char *raw, phpc_parsed_key *out)
{
    const char *p = raw;
    size_t base_len;

    out->count = 0;
    out->append_list = 0;
    if ('\0' == raw[0]) {
        return -1;
    }

    base_len = strcspn(p, "[");
    if (base_len > 0) {
        out->parts[out->count] = strndup(p, base_len);
        if (NULL == out->parts[out->count]) {
            return -1;
        }
        out->count++;
        p += base_len;
    }

    while ('[' == *p) {
        p++;
        if (']' == *p) {
            out->append_list = 1;
            p++;
            break;
        }
        {
            const char *close = strchr(p, ']');
            size_t len;

            if (NULL == close) {
                return -1;
            }
            len = (size_t) (close - p);
            out->parts[out->count] = malloc(len + 1);
            if (NULL == out->parts[out->count]) {
                return -1;
            }
            memcpy(out->parts[out->count], p, len);
            out->parts[out->count][len] = '\0';
            out->count++;
            p = close + 1;
        }
        if ('[' == *p && ']' == p[1]) {
            out->append_list = 1;
            p += 2;
        }
    }

    if ('\0' != *p || 0 == out->count) {
        return -1;
    }

    return 0;
}

static __hashtable__ *phpc_ensure_child(__hashtable__ *ht, const char *key)
{
    __string__ *k = phpc_cstr_to_string(key);
    __hashtable__ *child = __hashtable__readStringKeyHashtable(ht, k);

    if (NULL != child) {
        return child;
    }
    child = __hashtable__alloc();
    __hashtable__setStringKeyHashtable(ht, k, child);

    return child;
}

static void phpc_set_nested_value(__hashtable__ *root, phpc_parsed_key *pk, const char *value)
{
    __hashtable__ *ht = root;
    size_t last;
    const char *leaf;

    if (0 == pk->count) {
        return;
    }
    last = pk->count;
    {
        size_t i;

        for (i = 0; i + 1 < last; i++) {
            ht = phpc_ensure_child(ht, pk->parts[i]);
        }
    }
    leaf = pk->parts[last - 1];
    if (pk->append_list) {
        __hashtable__ *list_ht = phpc_ensure_child(ht, leaf);
        size_t idx = __hashtable__getNumElements(list_ht);

        __hashtable__setStringAt(list_ht, idx, phpc_cstr_to_string(value));

        return;
    }
    phpc_set_string_key(ht, leaf, value);
}

static void phpc_parse_delimited_pairs(__hashtable__ *ht, const char *body, char delimiter)
{
    char *copy;
    char *pair;
    char *saveptr;
    char delim[2];

    if (NULL == body || '\0' == body[0]) {
        return;
    }

    copy = strdup(body);
    if (NULL == copy) {
        return;
    }

    delim[0] = delimiter;
    delim[1] = '\0';
    pair = strtok_r(copy, delim, &saveptr);
    while (NULL != pair) {
        char *eq;
        char *raw_key;
        char *raw_val;
        phpc_parsed_key pk;

        eq = strchr(pair, '=');
        if (NULL != eq) {
            *eq = '\0';
            raw_key = pair;
            raw_val = eq + 1;
        } else {
            raw_key = pair;
            raw_val = pair + strlen(pair);
        }
        if ('\0' == raw_key[0]) {
            pair = strtok_r(NULL, delim, &saveptr);
            continue;
        }
        phpc_url_decode_inplace(raw_key);
        phpc_url_decode_inplace(raw_val);
        if (NULL == strchr(raw_key, '[')) {
            phpc_set_string_key(ht, raw_key, raw_val);
        } else if (0 == phpc_parse_key_brackets(raw_key, &pk)) {
            phpc_set_nested_value(ht, &pk, raw_val);
            phpc_free_parsed_key(&pk);
        } else {
            phpc_set_string_key(ht, raw_key, raw_val);
            phpc_free_parsed_key(&pk);
        }
        pair = strtok_r(NULL, delim, &saveptr);
    }

    free(copy);
}

void __compiler_parse_str(__hashtable__ *dest, __string__ *encoded)
{
    if (NULL == dest) {
        return;
    }

    phpc_parse_delimited_pairs(dest, phpc_string_data(encoded), '&');
}
