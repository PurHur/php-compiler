
/** Collect glob matches; returns count (>= 0) or -1 on error. Caller frees with __phpc_strvec_free. */
int __phpc_glob_vec(__string__ *pattern, int flags, char ***out_items)
{
    const char *pat; glob_t g; int rc; size_t i; size_t count;
    if (NULL == out_items) return -1; *out_items = NULL;
    if (NULL == pattern) return -1;
    pat = phpc_strdata(pattern); memset(&g, 0, sizeof(g));
    rc = glob(pat, flags, NULL, &g);
    if (GLOB_NOMATCH == rc) return 0;
    if (0 != rc) return -1;
    count = g.gl_pathc; if (0 == count) { globfree(&g); return 0; }
    *out_items = (char **) malloc(count * sizeof(char *));
    if (NULL == *out_items) { globfree(&g); return -1; }
    for (i = 0; i < count; i++) {
        (*out_items)[i] = strdup(g.gl_pathv[i]);
        if (NULL == (*out_items)[i]) {
            __phpc_strvec_free(*out_items, (int) i); *out_items = NULL; globfree(&g); return -1;
        }
    }
    globfree(&g); return (int) count;
}

int __phpc_scandir_vec(__string__ *path, int sorting_order, char ***out_items)
{
    const char *dir; struct dirent **namelist;
    int (*cmp)(const struct dirent **, const struct dirent **) = alphasort;
    int n, i;
    if (NULL == out_items) return -1; *out_items = NULL;
    if (NULL == path) return -1;
    dir = phpc_strdata(path);
    if (1 == sorting_order) cmp = phpc_scandir_desc; else if (2 == sorting_order) cmp = NULL;
    n = scandir(dir, &namelist, NULL, cmp);
    if (n < 0) return -1;
    if (0 == n) { free(namelist); return 0; }
    *out_items = (char **) malloc((size_t) n * sizeof(char *));
    if (NULL == *out_items) {
        for (i = 0; i < n; i++) free(namelist[i]); free(namelist); return -1;
    }
    for (i = 0; i < n; i++) {
        (*out_items)[i] = strdup(namelist[i]->d_name);
        if (NULL == (*out_items)[i]) {
            __phpc_strvec_free(*out_items, i); *out_items = NULL;
            for (; i < n; i++) free(namelist[i]); free(namelist); return -1;
        }
        free(namelist[i]);
    }
    free(namelist); return n;
}

void __phpc_strvec_free(char **items, int count)
{
    int i; if (NULL == items) return;
    for (i = 0; i < count; i++) free(items[i]); free(items);
}

