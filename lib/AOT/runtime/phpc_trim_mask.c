/*
 * trim/ltrim/rtrim custom character mask helper (issue #3709).
 * php-src ext/standard/string.c php_charmask subset (literal mask bytes).
 */

#include <stddef.h>
#include <stdint.h>

typedef struct __string__ __string__;

static int64_t trim_mask_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return *((int64_t *) ((char *) s + sizeof(void *)));
}

static const char *trim_mask_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(int64_t);
}

/** Whether byte ch appears in mask (issue #3709). */
int __phpc_char_in_mask(int ch, __string__ *mask)
{
    int64_t mask_len = trim_mask_strlen(mask);
    if (mask_len <= 0) {
        return 0;
    }
    const unsigned char byte = (unsigned char) ch;
    const char *data = trim_mask_strdata(mask);
    for (int64_t i = 0; i < mask_len; ++i) {
        if ((unsigned char) data[i] == byte) {
            return 1;
        }
    }

    return 0;
}
