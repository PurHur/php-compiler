/*
 * version_compare() for JIT/AOT (issue #6124).
 * php-src reference: ext/standard/versioning.c
 *
 * Remaining C after phpversion/php_uname/extension introspection moved to
 * lib/JIT/Builtin/StringInfo.php (LLVM). Full PHP lowering tracked separately.
 */

#include <ctype.h>
#include <stdint.h>
#include <stdlib.h>
#include <string.h>

typedef struct __string__ __string__;

static size_t phpc_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *phpc_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static char *phpc_canonicalize_version(const char *version)
{
    size_t len = strlen(version);
    char *buf;
    char *q;
    char lp;
    char lq;
    const char *p;

    if (0 == len) {
        buf = (char *) malloc(1);
        if (NULL != buf) {
            buf[0] = '\0';
        }

        return buf;
    }

    buf = (char *) malloc(len * 2 + 1);
    if (NULL == buf) {
        return NULL;
    }

    p = version;
    q = buf;
    *q++ = lp = *p++;

    while (*p) {
        lq = *(q - 1);
        if ('-' == *p || '_' == *p || '+' == *p) {
            if ('.' != lq) {
                *q++ = '.';
            }
        } else if (
            (!isdigit((unsigned char) lp) && '.' != lp && isdigit((unsigned char) *p))
            || (isdigit((unsigned char) lp) && !isdigit((unsigned char) *p) && '.' != *p)
        ) {
            if ('.' != lq) {
                *q++ = '.';
            }
            *q++ = *p;
        } else if (!isalnum((unsigned char) *p)) {
            if ('.' != lq) {
                *q++ = '.';
            }
        } else {
            *q++ = *p;
        }
        lp = *p++;
    }

    if ('.' == *(q - 1)) {
        *(q - 1) = '\0';
    } else {
        *q = '\0';
    }

    return buf;
}

static int phpc_compare_special_version_forms(const char *form1, const char *form2)
{
    static const struct {
        const char *name;
        int order;
    } special_forms[] = {
        {"dev", 0},
        {"alpha", 1},
        {"a", 1},
        {"beta", 2},
        {"b", 2},
        {"RC", 3},
        {"rc", 3},
        {"#", 4},
        {"pl", 5},
        {"p", 5},
        {NULL, 0},
    };
    int found1 = -1;
    int found2 = -1;
    size_t i;

    for (i = 0; special_forms[i].name != NULL; ++i) {
        if (0 == strncmp(form1, special_forms[i].name, strlen(special_forms[i].name))) {
            found1 = special_forms[i].order;
            break;
        }
    }
    for (i = 0; special_forms[i].name != NULL; ++i) {
        if (0 == strncmp(form2, special_forms[i].name, strlen(special_forms[i].name))) {
            found2 = special_forms[i].order;
            break;
        }
    }

    if (found1 == found2) {
        return 0;
    }
    if (found1 > found2) {
        return 1;
    }

    return -1;
}

static int phpc_normalize_compare(long long delta)
{
    if (delta > 0) {
        return 1;
    }
    if (delta < 0) {
        return -1;
    }

    return 0;
}

static int phpc_version_compare(const char *orig_ver1, const char *orig_ver2)
{
    char *ver1;
    char *ver2;
    char *p1;
    char *p2;
    char *n1;
    char *n2;
    long l1;
    long l2;
    int compare = 0;

    if (NULL == orig_ver1 || NULL == orig_ver2 || !*orig_ver1 || !*orig_ver2) {
        if ((NULL == orig_ver1 || !*orig_ver1) && (NULL == orig_ver2 || !*orig_ver2)) {
            return 0;
        }

        return (NULL != orig_ver1 && *orig_ver1) ? 1 : -1;
    }

    if ('#' == orig_ver1[0]) {
        ver1 = strdup(orig_ver1);
    } else {
        ver1 = phpc_canonicalize_version(orig_ver1);
    }
    if ('#' == orig_ver2[0]) {
        ver2 = strdup(orig_ver2);
    } else {
        ver2 = phpc_canonicalize_version(orig_ver2);
    }
    if (NULL == ver1 || NULL == ver2) {
        free(ver1);
        free(ver2);

        return 0;
    }

    p1 = n1 = ver1;
    p2 = n2 = ver2;
    while (*p1 && *p2 && n1 && n2) {
        n1 = strchr(p1, '.');
        if (NULL != n1) {
            *n1 = '\0';
        }
        n2 = strchr(p2, '.');
        if (NULL != n2) {
            *n2 = '\0';
        }
        if (isdigit((unsigned char) *p1) && isdigit((unsigned char) *p2)) {
            l1 = strtol(p1, NULL, 10);
            l2 = strtol(p2, NULL, 10);
            compare = phpc_normalize_compare((long long) l1 - (long long) l2);
        } else if (!isdigit((unsigned char) *p1) && !isdigit((unsigned char) *p2)) {
            compare = phpc_compare_special_version_forms(p1, p2);
        } else if (isdigit((unsigned char) *p1)) {
            compare = phpc_compare_special_version_forms("#N#", p2);
        } else {
            compare = phpc_compare_special_version_forms(p1, "#N#");
        }
        if (0 != compare) {
            break;
        }
        if (NULL != n1) {
            p1 = n1 + 1;
        }
        if (NULL != n2) {
            p2 = n2 + 1;
        }
    }
    if (0 == compare) {
        if (NULL != n1) {
            if (isdigit((unsigned char) *p1)) {
                compare = 1;
            } else {
                compare = phpc_version_compare(p1, "#N#");
            }
        } else if (NULL != n2) {
            if (isdigit((unsigned char) *p2)) {
                compare = -1;
            } else {
                compare = phpc_version_compare("#N#", p2);
            }
        }
    }

    free(ver1);
    free(ver2);

    return compare;
}

long long __compiler_version_compare(__string__ *ver1, __string__ *ver2)
{
    const char *s1 = (NULL == ver1) ? "" : phpc_strdata(ver1);
    const char *s2 = (NULL == ver2) ? "" : phpc_strdata(ver2);

    return (long long) phpc_version_compare(s1, s2);
}
