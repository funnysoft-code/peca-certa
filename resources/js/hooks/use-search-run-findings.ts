import { useHttp } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { index as findingsIndex } from '@/routes/search-runs/findings';

export const FINDINGS_PAGE_SIZE_PRESETS = [15, 25, 50] as const;

export type FindingsPageSize = (typeof FINDINGS_PAGE_SIZE_PRESETS)[number];

export type FindingsQueryState = {
    search: string;
    /** When true, send filter[in_stock]=1 (default). When false, omit filter (show all). */
    inStockOnly: boolean;
    sort: string | null;
    page: number;
    perPage: FindingsPageSize;
};

export type FindingsPaginatorLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type FindingsPaginatorLinks = {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
};

export type FindingsPaginatorMeta = {
    current_page: number;
    from: number | null;
    last_page: number;
    path: string | null;
    per_page: number;
    to: number | null;
    total: number;
    links: FindingsPaginatorLink[];
};

export type PaginatedFindings = {
    data: App.Data.FindingData[];
    links: FindingsPaginatorLinks;
    meta: FindingsPaginatorMeta;
};

const defaultQuery = (): FindingsQueryState => ({
    search: '',
    inStockOnly: true,
    sort: null,
    page: 1,
    perPage: 25,
});

function buildQueryParams(state: FindingsQueryState): {
    filter?: Record<string, string | boolean>;
    sort?: string;
    page: number;
    per_page: number;
} {
    const filter: Record<string, string | boolean> = {};
    const search = state.search.trim();

    if (search !== '') {
        filter.search = search;
    }

    if (state.inStockOnly) {
        filter.in_stock = true;
    }

    return {
        ...(Object.keys(filter).length > 0 ? { filter } : {}),
        ...(state.sort ? { sort: state.sort } : {}),
        page: state.page,
        per_page: state.perPage,
    };
}

/**
 * Server-driven findings list for a SearchRun.
 * Query string mirrors dreamshaper/ps-api: filter[x], sort, page, per_page.
 */
export function useSearchRunFindings(runId: string): {
    findings: PaginatedFindings | null;
    query: FindingsQueryState;
    loading: boolean;
    error: string | null;
    setSearch: (search: string) => void;
    setInStockOnly: (inStockOnly: boolean) => void;
    setSort: (sort: string | null) => void;
    setPage: (page: number) => void;
    setPerPage: (perPage: FindingsPageSize) => void;
    refetch: () => void;
} {
    const { submit } = useHttp();
    const [query, setQuery] = useState<FindingsQueryState>(defaultQuery);
    const [findings, setFindings] = useState<PaginatedFindings | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const requestId = useRef(0);
    const searchDebounce = useRef<ReturnType<typeof setTimeout> | null>(null);

    const fetchFindings = useCallback(
        async (state: FindingsQueryState): Promise<void> => {
            const id = ++requestId.current;
            setLoading(true);
            setError(null);

            try {
                const response = (await submit(
                    findingsIndex.get(runId, {
                        query: buildQueryParams(state),
                    }),
                )) as PaginatedFindings;

                if (id !== requestId.current) {
                    return;
                }

                setFindings(response);
            } catch {
                if (id !== requestId.current) {
                    return;
                }

                setError('Falha ao carregar resultados.');
            } finally {
                if (id === requestId.current) {
                    setLoading(false);
                }
            }
        },
        [runId, submit],
    );

    useEffect(() => {
        void fetchFindings(query);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- only re-fetch when query identity fields change
    }, [
        runId,
        query.search,
        query.inStockOnly,
        query.sort,
        query.page,
        query.perPage,
        fetchFindings,
    ]);

    const setSearch = useCallback((search: string): void => {
        if (searchDebounce.current !== null) {
            clearTimeout(searchDebounce.current);
        }

        searchDebounce.current = setTimeout(() => {
            setQuery((current) => ({
                ...current,
                search,
                page: 1,
            }));
        }, 300);
    }, []);

    const setInStockOnly = useCallback((inStockOnly: boolean): void => {
        setQuery((current) => ({
            ...current,
            inStockOnly,
            page: 1,
        }));
    }, []);

    const setSort = useCallback((sort: string | null): void => {
        setQuery((current) => ({
            ...current,
            sort,
            page: 1,
        }));
    }, []);

    const setPage = useCallback((page: number): void => {
        setQuery((current) => ({
            ...current,
            page: Math.max(1, page),
        }));
    }, []);

    const setPerPage = useCallback((perPage: FindingsPageSize): void => {
        setQuery((current) => ({
            ...current,
            perPage,
            page: 1,
        }));
    }, []);

    const refetch = useCallback((): void => {
        void fetchFindings(query);
    }, [fetchFindings, query]);

    useEffect(() => {
        return () => {
            if (searchDebounce.current !== null) {
                clearTimeout(searchDebounce.current);
            }
        };
    }, []);

    return {
        findings,
        query,
        loading,
        error,
        setSearch,
        setInStockOnly,
        setSort,
        setPage,
        setPerPage,
        refetch,
    };
}
