/*
 * str_getcsv() runtime for VM/JIT/AOT (issue #2391).
 * CSV line parser — keep in sync with phpc_parse_csv_line in phpc_stream.c.
 */

#include <stdlib.h>
#include <string.h>

typedef struct __string__ __string__;
typedef struct __hashtable__ __hashtable__;

extern __string__ *__string__init(long long size, const char *value);
extern __hashtable__ *__hashtable__alloc(void);
extern void __hashtable__setStringAt(__hashtable__ *ht, size_t index, __string__ *val);

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

static char phpc_csv_first_char(__string__ *s, char fallback)
{
    if (NULL == s || 0 == phpc_string_len(s)) {
        return fallback;
    }

    return phpc_string_data(s)[0];
}

static __hashtable__ *phpc_parse_csv_line(const char *line, char delim, char enclosure, char escape)
{
    __hashtable__ *ht;
    size_t field_idx = 0;
    size_t i = 0;
    size_t line_len = strlen(line);
    char *field = NULL;
    size_t field_cap = 0;
    size_t field_len = 0;
    int in_quotes = 0;

    ht = __hashtable__alloc();
    if (NULL == ht) {
        return NULL;
    }

    while (i <= line_len) {
        char c = (i < line_len) ? line[i] : '\0';

        if (in_quotes) {
            if ('\0' == c) {
                break;
            }
            if (c == escape && i + 1 < line_len) {
                if (field_len + 1 >= field_cap) {
                    size_t new_cap = field_cap < 32 ? 32 : field_cap * 2;
                    char *grown = (char *) realloc(field, new_cap);
                    if (NULL == grown) {
                        free(field);
                        return NULL;
                    }
                    field = grown;
                    field_cap = new_cap;
                }
                field[field_len++] = line[++i];
                ++i;
                continue;
            }
            if (c == enclosure) {
                if (i + 1 < line_len && line[i + 1] == enclosure) {
                    if (field_len + 1 >= field_cap) {
                        size_t new_cap = field_cap < 32 ? 32 : field_cap * 2;
                        char *grown = (char *) realloc(field, new_cap);
                        if (NULL == grown) {
                            free(field);
                            return NULL;
                        }
                        field = grown;
                        field_cap = new_cap;
                    }
                    field[field_len++] = enclosure;
                    i += 2;
                    continue;
                }
                in_quotes = 0;
                ++i;
                continue;
            }
            if (field_len + 1 >= field_cap) {
                size_t new_cap = field_cap < 32 ? 32 : field_cap * 2;
                char *grown = (char *) realloc(field, new_cap);
                if (NULL == grown) {
                    free(field);
                    return NULL;
                }
                field = grown;
                field_cap = new_cap;
            }
            field[field_len++] = c;
            ++i;
            continue;
        }

        if ('\0' == c || c == delim) {
            __string__ *str;
            if (NULL == field) {
                str = __string__init(0, "");
            } else {
                str = __string__init((long long) field_len, field);
                free(field);
                field = NULL;
                field_cap = 0;
                field_len = 0;
            }
            if (NULL == str) {
                return NULL;
            }
            __hashtable__setStringAt(ht, field_idx++, str);
            if ('\0' == c) {
                break;
            }
            ++i;
            continue;
        }

        if (c == enclosure) {
            in_quotes = 1;
            ++i;
            continue;
        }

        if (field_len + 1 >= field_cap) {
            size_t new_cap = field_cap < 32 ? 32 : field_cap * 2;
            char *grown = (char *) realloc(field, new_cap);
            if (NULL == grown) {
                free(field);
                return NULL;
            }
            field = grown;
            field_cap = new_cap;
        }
        field[field_len++] = c;
        ++i;
    }

    free(field);

    return ht;
}

__hashtable__ *__compiler_str_getcsv(
    __string__ *input,
    __string__ *separator,
    __string__ *enclosure,
    __string__ *escape
)
{
    char delim;
    char enc;
    char esc;

    if (NULL == input) {
        return NULL;
    }
    delim = phpc_csv_first_char(separator, ',');
    enc = phpc_csv_first_char(enclosure, '"');
    esc = phpc_csv_first_char(escape, '\\');

    return phpc_parse_csv_line(phpc_string_data(input), delim, enc, esc);
}
