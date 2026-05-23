/*
 * glob() / scandir() runtime for AOT/JIT (issue #665).
 * Uses libc glob(3) and scandir(3); PHP GLOB_* / SCANDIR_SORT_* flags pass through where compatible.
 */

#include <dirent.h>
#include <errno.h>
#include <glob.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>

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

static int phpc_path_is_dir(const char *path)
{
    struct stat st;

    return stat(path, &st) == 0 && S_ISDIR(st.st_mode);
}

static int phpc_mkdir_one(const char *path, mode_t mode)
{
    if (mkdir(path, mode) == 0) {
        return 1;
    }
    if (EEXIST == errno && phpc_path_is_dir(path)) {
        return 1;
    }

    return 0;
}

static int phpc_mkdir_recursive(const char *path, mode_t mode)
{
    char buf[4096];
    size_t len;
    char *p;

    if (NULL == path || '\0' == *path) {
        return 0;
    }
    if (phpc_path_is_dir(path)) {
        return 1;
    }
    if (strlen(path) >= sizeof(buf)) {
        return 0;
    }
    memcpy(buf, path, strlen(path) + 1);
    len = strlen(buf);
    if (len > 1 && '/' == buf[len - 1]) {
        buf[len - 1] = '\0';
    }
    for (p = buf + 1; *p != '\0'; p++) {
        if ('/' != *p) {
            continue;
        }
        *p = '\0';
        if ('\0' != buf[0] && !phpc_mkdir_one(buf, mode)) {
            return 0;
        }
        *p = '/';
    }

    return phpc_mkdir_one(buf, mode);
}

/** copy() runtime: returns 1 on success, 0 on failure. */
int __compiler_copy(__string__ *from, __string__ *to)
{
    const char *src;
    const char *dst;
    FILE *in;
    FILE *out;
    char buf[8192];
    size_t n;
    int ok = 1;

    if (NULL == from || NULL == to) {
        return 0;
    }
    src = phpc_strdata(from);
    dst = phpc_strdata(to);
    in = fopen(src, "rb");
    if (NULL == in) {
        return 0;
    }
    out = fopen(dst, "wb");
    if (NULL == out) {
        fclose(in);

        return 0;
    }
    while (1) {
        n = fread(buf, 1, sizeof(buf), in);
        if (n > 0 && fwrite(buf, 1, n, out) != n) {
            ok = 0;
            break;
        }
        if (n < sizeof(buf)) {
            if (ferror(in)) {
                ok = 0;
            }
            break;
        }
    }
    if (fclose(out) != 0) {
        ok = 0;
    }
    if (fclose(in) != 0) {
        ok = 0;
    }

    return ok;
}

/** mkdir() runtime: returns 1 on success, 0 on failure (issue #757). */
int __compiler_mkdir(__string__ *path, long long mode, int recursive)
{
    const char *p;
    mode_t m;

    if (NULL == path) {
        return 0;
    }
    p = phpc_strdata(path);
    m = (mode_t) mode;
    if (recursive) {
        return phpc_mkdir_recursive(p, m);
    }

    if (mkdir(p, m) == 0) {
        return 1;
    }

    return 0;
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
