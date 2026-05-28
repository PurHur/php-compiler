/*
 * preg_* runtime stubs for AOT/JIT bootstrap.
 *
 * Some bootstrap/self-host environments ship libpcre2 runtime libraries but not
 * development headers (pcre2.h) and/or do not resolve -lpcre2-8 during linking.
 * To keep the self-host compile/link path moving, provide conservative stubs
 * that report PHPC_PREG_BAD_REGEX.
 *
 * This is a bootstrap fallback, not a full regex implementation.
 */
 
#include <stdint.h>
#include <stddef.h>
 
typedef struct __hashtable__ __hashtable__;
typedef struct __string__ __string__;
 
/* PHP PREG_* codes (subset). */
#define PHPC_PREG_NO_ERROR 0
#define PHPC_PREG_BAD_REGEX 6
 
static int phpc_preg_last_error = PHPC_PREG_NO_ERROR;
 
int64_t __compiler_preg_last_error(void)
{
    return (int64_t) phpc_preg_last_error;
}
 
int64_t __compiler_preg_match(__string__ *pattern, __string__ *subject)
{
    (void) pattern;
    (void) subject;
    phpc_preg_last_error = PHPC_PREG_BAD_REGEX;
    return -1;
}
 
int64_t __compiler_preg_match_all(__string__ *pattern, __string__ *subject)
{
    (void) pattern;
    (void) subject;
    phpc_preg_last_error = PHPC_PREG_BAD_REGEX;
    return -1;
}
 
__string__ *__compiler_preg_replace(__string__ *pattern, __string__ *replacement, __string__ *subject)
{
    (void) pattern;
    (void) replacement;
    (void) subject;
    phpc_preg_last_error = PHPC_PREG_BAD_REGEX;
    return NULL;
}
 
__string__ *__compiler_preg_replace_callback(__string__ *pattern, void *callback, __string__ *subject)
{
    (void) pattern;
    (void) callback;
    (void) subject;
    phpc_preg_last_error = PHPC_PREG_BAD_REGEX;
    return NULL;
}
 
__hashtable__ *__compiler_preg_split(__string__ *pattern, __string__ *subject)
{
    (void) pattern;
    (void) subject;
    phpc_preg_last_error = PHPC_PREG_BAD_REGEX;
    return NULL;
}
 
