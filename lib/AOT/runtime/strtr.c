/*
 * strtr() runtime for AOT/JIT (issue #1030, #3785).
 * Two-string byte translation table and replace_pairs array form.
 *
 * @see php/php-src ext/standard/string.c php_strtr_array()
 */

#include <stddef.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

typedef struct __string__ __string__;
typedef struct __hashtable__ __hashtable__;

typedef struct __ref__ {
    int32_t refcount;
    int32_t typeinfo;
} __ref__;

typedef struct __value__ {
    int8_t type;
    int8_t value[8];
} __value__;

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

#define PHPC_TYPE_NULL 0
#define PHPC_TYPE_STRING 4

static size_t strtr_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *strtr_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

extern __string__ *__value__readString(__value__ *v);

static int strtr_value_is_null(const __value__ *v)
{
    return PHPC_TYPE_NULL == ((int) (v->type & 0x7f));
}

static __string__ *strtr_value_to_string(__value__ *v)
{
    if (NULL == v || strtr_value_is_null(v)) {
        return __string__init(0, "");
    }
    if (PHPC_TYPE_STRING == ((int) (v->type & 0x7f))) {
        return __value__readString(v);
    }

    return __string__init(0, "");
}

typedef struct {
    char *key;
    size_t key_len;
    char *val;
    size_t val_len;
} strtr_pair;

static __string__ *strtr_copy_string(__string__ *subject)
{
    size_t slen = strtr_strlen(subject);
    const char *sdata = strtr_strdata(subject);

    return __string__init((long long) slen, sdata);
}

static __string__ *strtr_build_result(const char *buf, size_t len)
{
    char *copy;
    __string__ *result;

    if (0 == len) {
        return __string__init(0, "");
    }
    copy = (char *) malloc(len);
    if (NULL == copy) {
        return __string__init(0, "");
    }
    memcpy(copy, buf, len);
    result = __string__init((long long) len, copy);
    free(copy);

    return result;
}

__string__ *__compiler_strtr(__string__ *subject, __string__ *from, __string__ *to)
{
    size_t slen = strtr_strlen(subject);
    const char *sdata = strtr_strdata(subject);
    size_t flen = strtr_strlen(from);
    const char *fdata = strtr_strdata(from);
    size_t tlen = strtr_strlen(to);
    const char *tdata = strtr_strdata(to);

    if (0 == flen) {
        return subject;
    }

    unsigned char table[256];
    for (int i = 0; i < 256; i++) {
        table[i] = (unsigned char) i;
    }
    size_t plen = flen < tlen ? flen : tlen;
    for (size_t i = 0; i < plen; i++) {
        table[(unsigned char) fdata[i]] = (unsigned char) tdata[i];
    }

    if (0 == slen) {
        return __string__init(0, "");
    }

    char *outbuf = (char *) malloc(slen);
    if (NULL == outbuf) {
        return __string__init(0, "");
    }
    for (size_t i = 0; i < slen; i++) {
        outbuf[i] = (char) table[(unsigned char) sdata[i]];
    }
    __string__ *result = __string__init((long long) slen, outbuf);
    free(outbuf);

    return result;
}

static int strtr_pair_add(
    strtr_pair **pairs,
    size_t *count,
    size_t *capacity,
    const char *key,
    size_t key_len,
    const char *val,
    size_t val_len
)
{
    strtr_pair *next;

    if (0 == key_len) {
        return 0;
    }
    if (*count >= *capacity) {
        size_t new_cap = (*capacity == 0) ? 8 : (*capacity * 2);
        next = (strtr_pair *) realloc(*pairs, new_cap * sizeof(strtr_pair));
        if (NULL == next) {
            return -1;
        }
        *pairs = next;
        *capacity = new_cap;
    }
    (*pairs)[*count].key = (char *) malloc(key_len);
    (*pairs)[*count].val = (char *) malloc(val_len);
    if (NULL == (*pairs)[*count].key || NULL == (*pairs)[*count].val) {
        free((*pairs)[*count].key);
        free((*pairs)[*count].val);
        return -1;
    }
    memcpy((*pairs)[*count].key, key, key_len);
    memcpy((*pairs)[*count].val, val, val_len);
    (*pairs)[*count].key_len = key_len;
    (*pairs)[*count].val_len = val_len;
    ++(*count);

    return 0;
}

static void strtr_pairs_free(strtr_pair *pairs, size_t count)
{
    size_t i;

    if (NULL == pairs) {
        return;
    }
    for (i = 0; i < count; ++i) {
        free(pairs[i].key);
        free(pairs[i].val);
    }
    free(pairs);
}

static int strtr_collect_pairs(__hashtable__ *pats, size_t slen, strtr_pair **pairs, size_t *count)
{
    size_t capacity = 0;
    __strkey_node__ *node;
    size_t index;
    char num_buf[32];

    *pairs = NULL;
    *count = 0;

    if (NULL == pats) {
        return 0;
    }

    for (node = pats->strKeys; NULL != node; node = node->next) {
        __string__ *val_str;
        size_t key_len;
        size_t val_len;
        const char *key_data;
        const char *val_data;

        key_len = strtr_strlen(node->key);
        if (key_len > slen) {
            continue;
        }
        val_str = strtr_value_to_string(&node->value);
        key_data = strtr_strdata(node->key);
        val_data = strtr_strdata(val_str);
        val_len = strtr_strlen(val_str);
        if (0 != strtr_pair_add(pairs, count, &capacity, key_data, key_len, val_data, val_len)) {
            strtr_pairs_free(*pairs, *count);
            return -1;
        }
    }

    for (index = 0; index < pats->nextFreeElement; ++index) {
        __value__ *entry = &pats->values[index];
        __string__ *val_str;
        size_t val_len;
        const char *val_data;
        int n;

        if (strtr_value_is_null(entry)) {
            continue;
        }
        n = snprintf(num_buf, sizeof num_buf, "%zu", index);
        if (n <= 0 || (size_t) n >= sizeof num_buf) {
            continue;
        }
        if ((size_t) n > slen) {
            continue;
        }
        val_str = strtr_value_to_string(entry);
        val_data = strtr_strdata(val_str);
        val_len = strtr_strlen(val_str);
        if (0 != strtr_pair_add(pairs, count, &capacity, num_buf, (size_t) n, val_data, val_len)) {
            strtr_pairs_free(*pairs, *count);
            return -1;
        }
    }

    return 0;
}

static const char *strtr_find_substring(const char *haystack, size_t haystack_len, const char *needle, size_t needle_len, size_t offset)
{
    size_t i;

    if (0 == needle_len || offset >= haystack_len) {
        return NULL;
    }
    for (i = offset; i + needle_len <= haystack_len; ++i) {
        if (0 == memcmp(haystack + i, needle, needle_len)) {
            return haystack + i;
        }
    }

    return NULL;
}

static __string__ *strtr_single_pair(const char *str, size_t slen, strtr_pair *pair)
{
    if (1 == pair->key_len) {
        __string__ *subject = __string__init((long long) slen, str);
        __string__ *from = __string__init(1, pair->key);
        char to_buf[1];

        to_buf[0] = (pair->val_len > 0) ? pair->val[0] : '\0';
        return __compiler_strtr(subject, from, __string__init(1, to_buf));
    }

    {
        char *outbuf = NULL;
        size_t outlen = 0;
        size_t outcap = slen + 1;
        size_t pos = 0;

        outbuf = (char *) malloc(outcap);
        if (NULL == outbuf) {
            return __string__init((long long) slen, str);
        }

        while (pos < slen) {
            const char *found = strtr_find_substring(str, slen, pair->key, pair->key_len, pos);
            size_t found_at;

            if (NULL == found) {
                size_t tail = slen - pos;
                if (outlen + tail > outcap) {
                    outcap = outlen + tail + pair->val_len + 1;
                    {
                        char *grown = (char *) realloc(outbuf, outcap);
                        if (NULL == grown) {
                            free(outbuf);
                            return __string__init((long long) slen, str);
                        }
                        outbuf = grown;
                    }
                }
                memcpy(outbuf + outlen, str + pos, tail);
                outlen += tail;
                break;
            }

            found_at = (size_t) (found - str);
            if (outlen + (found_at - pos) + pair->val_len > outcap) {
                outcap = outlen + (found_at - pos) + pair->val_len + slen + 1;
                {
                    char *grown = (char *) realloc(outbuf, outcap);
                    if (NULL == grown) {
                        free(outbuf);
                        return __string__init((long long) slen, str);
                    }
                    outbuf = grown;
                }
            }
            memcpy(outbuf + outlen, str + pos, found_at - pos);
            outlen += found_at - pos;
            memcpy(outbuf + outlen, pair->val, pair->val_len);
            outlen += pair->val_len;
            pos = found_at + pair->key_len;
        }

        {
            __string__ *result = strtr_build_result(outbuf, outlen);
            free(outbuf);
            return result;
        }
    }
}

static const strtr_pair *strtr_find_pair(const strtr_pair *pairs, size_t count, const char *key, size_t key_len)
{
    size_t i;

    for (i = 0; i < count; ++i) {
        if (pairs[i].key_len == key_len && 0 == memcmp(pairs[i].key, key, key_len)) {
            return &pairs[i];
        }
    }

    return NULL;
}

static __string__ *strtr_longest_match(const char *str, size_t slen, strtr_pair *pairs, size_t count)
{
    size_t minlen = slen + 1;
    size_t maxlen = 0;
    unsigned char first_chars[256];
    unsigned char lengths[256];
    char *outbuf = NULL;
    size_t outlen = 0;
    size_t outcap = slen + 1;
    size_t pos = 0;
    size_t old_pos = 0;
    size_t i;

    memset(first_chars, 0, sizeof first_chars);
    memset(lengths, 0, sizeof lengths);

    for (i = 0; i < count; ++i) {
        if (pairs[i].key_len < minlen) {
            minlen = pairs[i].key_len;
        }
        if (pairs[i].key_len > maxlen) {
            maxlen = pairs[i].key_len;
        }
        first_chars[(unsigned char) pairs[i].key[0]] = 1;
        if (pairs[i].key_len < sizeof lengths) {
            lengths[pairs[i].key_len] = 1;
        }
    }

    if (minlen > maxlen) {
        return __string__init((long long) slen, str);
    }

    outbuf = (char *) malloc(outcap);
    if (NULL == outbuf) {
        return __string__init((long long) slen, str);
    }

    while (pos <= slen - minlen) {
        if (first_chars[(unsigned char) str[pos]]) {
            size_t try_len = maxlen;
            if (try_len > slen - pos) {
                try_len = slen - pos;
            }
            while (try_len >= minlen) {
                const strtr_pair *match = NULL;

                if (try_len < sizeof lengths && lengths[try_len]) {
                    match = strtr_find_pair(pairs, count, str + pos, try_len);
                }
                if (NULL != match) {
                    size_t need = outlen + (pos - old_pos) + match->val_len;
                    if (need > outcap) {
                        outcap = need + slen + 1;
                        {
                            char *grown = (char *) realloc(outbuf, outcap);
                            if (NULL == grown) {
                                free(outbuf);
                                return __string__init((long long) slen, str);
                            }
                            outbuf = grown;
                        }
                    }
                    memcpy(outbuf + outlen, str + old_pos, pos - old_pos);
                    outlen += pos - old_pos;
                    memcpy(outbuf + outlen, match->val, match->val_len);
                    outlen += match->val_len;
                    old_pos = pos + try_len;
                    pos = old_pos - 1;
                    break;
                }
                --try_len;
            }
        }
        ++pos;
    }

    if (outlen > 0) {
        size_t tail = slen - old_pos;
        if (outlen + tail > outcap) {
            outcap = outlen + tail + 1;
            {
                char *grown = (char *) realloc(outbuf, outcap);
                if (NULL == grown) {
                    free(outbuf);
                    return __string__init((long long) slen, str);
                }
                outbuf = grown;
            }
        }
        memcpy(outbuf + outlen, str + old_pos, tail);
        outlen += tail;
        {
            __string__ *result = strtr_build_result(outbuf, outlen);
            free(outbuf);
            return result;
        }
    }

    free(outbuf);
    return __string__init((long long) slen, str);
}

__string__ *__compiler_strtr_array(__string__ *subject, __hashtable__ *replace_pairs)
{
    size_t slen;
    const char *sdata;
    strtr_pair *pairs = NULL;
    size_t count = 0;
    __string__ *result;

    slen = strtr_strlen(subject);
    if (0 == slen) {
        return __string__init(0, "");
    }
    sdata = strtr_strdata(subject);

    if (0 != strtr_collect_pairs(replace_pairs, slen, &pairs, &count)) {
        return strtr_copy_string(subject);
    }
    if (0 == count) {
        return strtr_copy_string(subject);
    }
    if (1 == count) {
        result = strtr_single_pair(sdata, slen, &pairs[0]);
        strtr_pairs_free(pairs, count);
        return result;
    }

    result = strtr_longest_match(sdata, slen, pairs, count);
    strtr_pairs_free(pairs, count);

    return result;
}
