/*
 * glob() / scandir() runtime for AOT/JIT (issue #665).
 * Uses libc glob(3) and scandir(3); PHP GLOB_* / SCANDIR_SORT_* flags pass through where compatible.
 */

#include <dirent.h>
#include <errno.h>
#include <glob.h>
#include <stdlib.h>
#include <string.h>

typedef struct __hashtable__ __hashtable__;
typedef struct __string__ __string__;

extern __hashtable__ *__hashtable__alloc(void);
extern void __hashtable__setStringAt(__hashtable__ *ht, size_t index, __string__ *val);
extern __string__ *__string__init(long long size, const char *value);

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

static __string__ *cstr_to_string(const char *cstr)
{
    size_t len = strlen(cstr);

    return __string__init((long long) len, cstr);
}

/** PHP SCANDIR_SORT_DESCENDING */
static int phpc_scandir_desc(const struct dirent **a, const struct dirent **b)
{
    return strcmp((*b)->d_name, (*a)->d_name);
}

__hashtable__ *__phpc_glob(__string__ *pattern, int flags)
{
    const char *pat;
    glob_t g;
    int rc;
    __hashtable__ *ht;
    size_t i;

    if (NULL == pattern) {
        return NULL;
    }
    pat = phpc_strdata(pattern);
    memset(&g, 0, sizeof(g));
    rc = glob(pat, flags, NULL, &g);
    if (GLOB_NOMATCH == rc) {
        return __hashtable__alloc();
    }
    if (0 != rc) {
        return NULL;
    }
    ht = __hashtable__alloc();
    for (i = 0; i < g.gl_pathc; i++) {
        __hashtable__setStringAt(ht, i, cstr_to_string(g.gl_pathv[i]));
    }
    globfree(&g);

    return ht;
}

__hashtable__ *__phpc_scandir(__string__ *path, int sorting_order)
{
    const char *dir;
    struct dirent **namelist;
    int (*cmp)(const struct dirent **, const struct dirent **) = alphasort;
    int n;
    __hashtable__ *ht;
    int i;

    if (NULL == path) {
        return NULL;
    }
    dir = phpc_strdata(path);
    if (1 == sorting_order) {
        cmp = phpc_scandir_desc;
    } else if (2 == sorting_order) {
        cmp = NULL;
    }
    n = scandir(dir, &namelist, NULL, cmp);
    if (n < 0) {
        return NULL;
    }
    ht = __hashtable__alloc();
    for (i = 0; i < n; i++) {
        __hashtable__setStringAt(ht, (size_t) i, cstr_to_string(namelist[i]->d_name));
        free(namelist[i]);
    }
    free(namelist);

    return ht;
}
