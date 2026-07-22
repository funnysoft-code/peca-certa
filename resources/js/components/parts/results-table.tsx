import { type ColumnDef } from '@tanstack/react-table';
import { ArrowUpDown, ChevronLeft, ChevronRight, Group } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import { Input } from '@/components/ui/input';
import { Toggle } from '@/components/ui/toggle';
import {
    FINDINGS_PAGE_SIZE_PRESETS,
    type FindingsPageSize,
    type PaginatedFindings,
} from '@/hooks/use-search-run-findings';
import { cn } from '@/lib/utils';

const SUPPLIER_LABELS: Record<App.Enums.Supplier, string> = {
    autodelta: 'Auto Delta',
    autozitania: 'Auto Zitânia',
};

type StockMode = 'quantity' | 'availability';

const SUPPLIER_STOCK_MODES: Record<App.Enums.Supplier, StockMode> = {
    autodelta: 'quantity',
    autozitania: 'availability',
};

function stockLabel(finding: App.Data.FindingData): string {
    if (SUPPLIER_STOCK_MODES[finding.supplier] === 'quantity') {
        return String(finding.availableQuantity);
    }

    return finding.inStock ? 'Disponível' : 'Indisponível';
}

function SortableHeader({
    label,
    sorted,
    onToggle,
    align = 'left',
}: {
    label: string;
    sorted: false | 'asc' | 'desc';
    onToggle: () => void;
    align?: 'left' | 'right';
}) {
    return (
        <Button
            variant="ghost"
            size="sm"
            className={
                align === 'right'
                    ? '-mr-2 h-8 gap-1 px-2 hover:bg-transparent'
                    : '-ml-2 h-8 gap-1 px-2 hover:bg-transparent'
            }
            onClick={onToggle}
        >
            {label}
            <ArrowUpDown
                className={
                    sorted ? 'size-3.5 opacity-100' : 'size-3.5 opacity-40'
                }
            />
        </Button>
    );
}

function sortDirection(
    current: string | null,
    field: string,
): false | 'asc' | 'desc' {
    if (current === field) {
        return 'asc';
    }

    if (current === `-${field}`) {
        return 'desc';
    }

    return false;
}

function nextSort(current: string | null, field: string): string | null {
    if (current === field) {
        return `-${field}`;
    }

    if (current === `-${field}`) {
        return null;
    }

    return field;
}

type ResultsTableProps = {
    findings: PaginatedFindings | null;
    loading: boolean;
    error: string | null;
    search: string;
    inStockOnly: boolean;
    sort: string | null;
    page: number;
    perPage: FindingsPageSize;
    onSearchChange: (search: string) => void;
    onInStockOnlyChange: (inStockOnly: boolean) => void;
    onSortChange: (sort: string | null) => void;
    onPageChange: (page: number) => void;
    onPerPageChange: (perPage: FindingsPageSize) => void;
};

export function ResultsTable({
    findings,
    loading,
    error,
    search,
    inStockOnly,
    sort,
    page,
    perPage,
    onSearchChange,
    onInStockOnlyChange,
    onSortChange,
    onPageChange,
    onPerPageChange,
}: ResultsTableProps) {
    const [groupBySupplier, setGroupBySupplier] = useState(true);
    const [searchInput, setSearchInput] = useState(search);

    const columns = useMemo<ColumnDef<App.Data.FindingData>[]>(
        () => [
            {
                id: 'supplier',
                accessorFn: (row) => SUPPLIER_LABELS[row.supplier],
                enableGrouping: true,
                enableSorting: false,
                aggregationFn: undefined,
                header: () => (
                    <SortableHeader
                        label="Fornecedor"
                        sorted={sortDirection(sort, 'supplier')}
                        onToggle={() =>
                            onSortChange(nextSort(sort, 'supplier'))
                        }
                    />
                ),
                cell: ({ getValue }) => (
                    <span className="text-muted-foreground">
                        {String(getValue())}
                    </span>
                ),
            },
            {
                id: 'brand',
                accessorFn: (row) => row.brand,
                enableGrouping: false,
                enableSorting: false,
                aggregationFn: undefined,
                header: () => (
                    <SortableHeader
                        label="Marca"
                        sorted={sortDirection(sort, 'brand')}
                        onToggle={() => onSortChange(nextSort(sort, 'brand'))}
                    />
                ),
                cell: ({ row }) => (
                    <span className="font-medium">{row.original.brand}</span>
                ),
            },
            {
                id: 'article',
                accessorFn: (row) => row.article,
                enableGrouping: false,
                enableSorting: false,
                aggregationFn: undefined,
                header: () => (
                    <SortableHeader
                        label="Artigo"
                        sorted={sortDirection(sort, 'article')}
                        onToggle={() => onSortChange(nextSort(sort, 'article'))}
                    />
                ),
                cell: ({ row }) => row.original.article,
            },
            {
                id: 'price',
                accessorFn: (row) => row.price,
                enableGrouping: false,
                enableSorting: false,
                aggregationFn: undefined,
                header: () => (
                    <div className="text-right">
                        <SortableHeader
                            label="Preço"
                            sorted={sortDirection(sort, 'price')}
                            onToggle={() =>
                                onSortChange(nextSort(sort, 'price'))
                            }
                            align="right"
                        />
                    </div>
                ),
                cell: ({ row }) => (
                    <span className="tabular-nums">
                        {row.original.price?.toFixed(2) ?? '–'}
                    </span>
                ),
                meta: {
                    headerClassName: 'text-right',
                    cellClassName: 'text-right',
                },
            },
            {
                id: 'stock',
                accessorFn: (row) => row.availableQuantity,
                enableGrouping: false,
                enableSorting: false,
                aggregationFn: undefined,
                header: () => (
                    <div className="text-right">
                        <SortableHeader
                            label="Stock"
                            sorted={sortDirection(sort, 'available_quantity')}
                            onToggle={() =>
                                onSortChange(
                                    nextSort(sort, 'available_quantity'),
                                )
                            }
                            align="right"
                        />
                    </div>
                ),
                cell: ({ row }) => (
                    <span
                        className={
                            row.original.inStock
                                ? 'text-emerald-600 tabular-nums'
                                : 'text-muted-foreground tabular-nums'
                        }
                    >
                        {stockLabel(row.original)}
                    </span>
                ),
                meta: {
                    headerClassName: 'text-right',
                    cellClassName: 'text-right',
                },
            },
        ],
        [onSortChange, sort],
    );

    const rows = findings?.data ?? [];
    const meta = findings?.meta;
    const lastPage = meta?.last_page ?? 1;
    const total = meta?.total ?? 0;

    return (
        <div className="space-y-3">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <Input
                    value={searchInput}
                    onChange={(event) => {
                        const value = event.target.value;
                        setSearchInput(value);
                        onSearchChange(value);
                    }}
                    placeholder="Pesquisar fornecedor, marca ou artigo…"
                    className="sm:max-w-xs"
                    aria-label="Pesquisar resultados"
                />

                <div className="flex flex-wrap items-center justify-end gap-2">
                    <Toggle
                        variant="outline"
                        size="sm"
                        pressed={!inStockOnly}
                        onPressedChange={(pressed) =>
                            onInStockOnlyChange(!pressed)
                        }
                        aria-label="Mostrar indisponíveis"
                        className="px-2.5"
                    >
                        Mostrar indisponíveis
                    </Toggle>

                    <Toggle
                        variant="outline"
                        size="sm"
                        pressed={groupBySupplier}
                        onPressedChange={setGroupBySupplier}
                        aria-label="Agrupar por fornecedor"
                        className="px-2.5"
                    >
                        <Group className="size-3.5" />
                        Agrupar por Fornecedor
                    </Toggle>
                </div>
            </div>

            {error && (
                <p className="text-sm text-destructive" role="alert">
                    {error}
                </p>
            )}

            <div className={cn(loading && 'opacity-60 transition-opacity')}>
                <DataTable
                    columns={columns}
                    data={rows}
                    emptyMessage={
                        loading ? 'A carregar resultados…' : 'Sem resultados.'
                    }
                    getRowId={(row) => row.id}
                    grouping={groupBySupplier ? ['supplier'] : []}
                />
            </div>

            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <span>Por página</span>
                    <div className="flex gap-1">
                        {FINDINGS_PAGE_SIZE_PRESETS.map((size) => (
                            <Button
                                key={size}
                                type="button"
                                size="sm"
                                variant={
                                    perPage === size ? 'default' : 'outline'
                                }
                                className="h-8 w-10 px-0"
                                onClick={() => onPerPageChange(size)}
                            >
                                {size}
                            </Button>
                        ))}
                    </div>
                    {total > 0 && (
                        <span className="tabular-nums">
                            {meta?.from ?? 0}–{meta?.to ?? 0} de {total}
                        </span>
                    )}
                </div>

                <div className="flex items-center gap-2">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={page <= 1 || loading}
                        onClick={() => onPageChange(page - 1)}
                        aria-label="Página anterior"
                    >
                        <ChevronLeft className="size-4" />
                    </Button>
                    <span className="min-w-16 text-center text-sm text-muted-foreground tabular-nums">
                        {page} / {lastPage}
                    </span>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={page >= lastPage || loading}
                        onClick={() => onPageChange(page + 1)}
                        aria-label="Página seguinte"
                    >
                        <ChevronRight className="size-4" />
                    </Button>
                </div>
            </div>
        </div>
    );
}
