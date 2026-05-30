/*
 * gethostname() runtime for JIT/AOT (issue #3465).
 * Uses gethostname(2); php-src reference: ext/standard/basic_functions.c
 */

#include <stddef.h>
#include <string.h>
#include <unistd.h>

typedef struct __string__ __string__;

extern __string__ *__string__init(long long size, const char *value);

__string__ *__compiler_gethostname(void)
{
    char buffer[256];

    if (0 != gethostname(buffer, sizeof(buffer))) {
        return NULL;
    }
    if ('\0' == buffer[0]) {
        return NULL;
    }

    return __string__init((long long) strlen(buffer), buffer);
}
