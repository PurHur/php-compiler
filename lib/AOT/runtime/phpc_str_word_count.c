/*
 * str_word_count() format 1/2 (+ optional chars) for JIT/AOT (issue #3584).
 * Subset of php-src ext/standard/string.c — ASCII letters, in-word ' and -.
 */

#include <stddef.h>
#include <string.h>

typedef struct __hashtable__ __hashtable__;
typedef struct __string__ __string__;

extern __hashtable__ *__hashtable__alloc(void);
extern void __hashtable__setStringAt(__hashtable__ *ht, size_t index, __string__ *val);
extern __string__ *__string__init(long long size, const char *value);

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

static int phpc_is_letter(unsigned char c)
{
    return (c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z');
}

static int phpc_extra_mask(const unsigned char *extra, unsigned char c)
{
    if (NULL == extra) {
        return 0;
    }

    return extra[c] != 0;
}

static int phpc_is_word_char(unsigned char c, int in_word, const unsigned char *extra)
{
    if (phpc_extra_mask(extra, c)) {
        return 1;
    }
    if (phpc_is_letter(c)) {
        return 1;
    }

    return in_word && (c == 39 || c == 45);
}

static __string__ *phpc_slice(const char *data, size_t start, size_t end)
{
    size_t len;

    if (NULL == data || end <= start) {
        return __string__init(0, "");
    }
    len = end - start;

    return __string__init((long long) len, data + start);
}

static void phpc_append_word(
    __hashtable__ *ht,
    const char *data,
    size_t word_start,
    size_t pos,
    long long format,
    size_t *list_idx
)
{
    __string__ *word;

    word = phpc_slice(data, word_start, pos);
    if (NULL == word) {
        return;
    }
    if (1 == format) {
        __hashtable__setStringAt(ht, *list_idx, word);
        (*list_idx)++;
    } else {
        __hashtable__setStringAt(ht, (size_t) word_start, word);
    }
}

__hashtable__ *__compiler_str_word_count_words(__string__ *str, long long format, __string__ *chars)
{
    const char *data;
    size_t len;
    size_t i;
    size_t word_start = 0;
    size_t list_idx = 0;
    int in_word = 0;
    unsigned char extra[256];
    __hashtable__ *ht;

    if (format != 1 && format != 2) {
        return NULL;
    }

    memset(extra, 0, sizeof(extra));
    if (NULL != chars && phpc_string_len(chars) > 0) {
        const char *clist = phpc_string_data(chars);
        size_t clen = phpc_string_len(chars);
        size_t j;

        for (j = 0; j < clen; j++) {
            extra[(unsigned char) clist[j]] = 1;
        }
    }

    data = phpc_string_data(str);
    len = phpc_string_len(str);
    ht = __hashtable__alloc();

    for (i = 0; i < len; i++) {
        unsigned char c = (unsigned char) data[i];
        if (phpc_is_word_char(c, in_word, extra)) {
            if (!in_word) {
                word_start = i;
                in_word = 1;
            }
        } else if (in_word) {
            phpc_append_word(ht, data, word_start, i, format, &list_idx);
            in_word = 0;
        }
    }
    if (in_word) {
        phpc_append_word(ht, data, word_start, len, format, &list_idx);
    }

    return ht;
}
