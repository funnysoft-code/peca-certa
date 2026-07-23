import { type ColumnDef } from '@tanstack/react-table';
import {
    ArrowUpDown,
    ChevronLeft,
    ChevronRight,
    CopyIcon,
    Group,
    MoreHorizontalIcon,
    SearchIcon,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    ContextMenu,
    ContextMenuContent,
    ContextMenuGroup,
    ContextMenuItem,
    ContextMenuTrigger,
} from '@/components/ui/context-menu';
import { DataTable } from '@/components/ui/data-table';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Toggle } from '@/components/ui/toggle';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    FINDINGS_PAGE_SIZE_PRESETS,
    type FindingsPageSize,
    type PaginatedFindings,
} from '@/hooks/use-search-run-findings';

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

async function copyText(value: string, label: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        toast.success(`${label} copiado`);
    } catch {
        toast.error(`Não foi possível copiar ${label.toLowerCase()}`);
    }
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

function RowActions({ finding }: { finding: App.Data.FindingData }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    aria-label="Ações da linha"
                >
                    <MoreHorizontalIcon />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuGroup>
                    <DropdownMenuItem
                        onClick={() => void copyText(finding.article, 'Artigo')}
                    >
                        <CopyIcon />
                        Copiar artigo
                    </DropdownMenuItem>
                    {finding.traderArticleNumber ? (
                        <DropdownMenuItem
                            onClick={() =>
                                void copyText(
                                    finding.traderArticleNumber,
                                    'Ref. fornecedor',
                                )
                            }
                        >
                            <CopyIcon />
                            Copiar ref. fornecedor
                        </DropdownMenuItem>
                    ) : null}
                </DropdownMenuGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
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
                    <div className="flex justify-end">
                        <Badge
                            variant={
                                row.original.inStock ? 'default' : 'outline'
                            }
                            className="tabular-nums"
                        >
                            {stockLabel(row.original)}
                        </Badge>
                    </div>
                ),
                meta: {
                    headerClassName: 'text-right',
                    cellClassName: 'text-right',
                },
            },
            {
                id: 'actions',
                enableGrouping: false,
                enableSorting: false,
                header: () => <span className="sr-only">Ações</span>,
                cell: ({ row }) => <RowActions finding={row.original} />,
                meta: {
                    headerClassName: 'w-10',
                    cellClassName: 'w-10',
                },
            },
        ],
        [onSortChange, sort],
    );

    const rows = findings?.data ?? [];
    const meta = findings?.meta;
    const lastPage = meta?.last_page ?? 1;
    const total = meta?.total ?? 0;
    const showEmpty = !loading && rows.length === 0;

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <InputGroup className="sm:max-w-xs">
                    <InputGroupAddon align="inline-start">
                        <SearchIcon />
                    </InputGroupAddon>
                    <InputGroupInput
                        value={searchInput}
                        onChange={(event) => {
                            const value = event.target.value;
                            setSearchInput(value);
                            onSearchChange(value);
                        }}
                        placeholder="Pesquisar fornecedor, marca ou artigo…"
                        aria-label="Pesquisar resultados"
                    />
                </InputGroup>

                <div className="flex flex-wrap items-center justify-end gap-2">
                    <Toggle
                        variant="outline"
                        size="sm"
                        pressed={!inStockOnly}
                        onPressedChange={(pressed) =>
                            onInStockOnlyChange(!pressed)
                        }
                        aria-label={
                            inStockOnly
                                ? 'Mostrar indisponíveis'
                                : 'Esconder indisponíveis'
                        }
                        className="px-2.5"
                    >
                        {inStockOnly
                            ? 'Mostrar indisponíveis'
                            : 'Esconder indisponíveis'}
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

            {showEmpty ? (
                <Empty className="border border-dashed">
                    <EmptyHeader>
                        <EmptyMedia variant="icon">
                            <SearchIcon />
                        </EmptyMedia>
                        <EmptyTitle>Sem resultados</EmptyTitle>
                        <EmptyDescription>
                            Ajuste a pesquisa ou mostre indisponíveis.
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : (
                <ContextMenu>
                    <ContextMenuTrigger asChild>
                        <div>
                            <DataTable
                                columns={columns}
                                data={rows}
                                emptyMessage={
                                    loading
                                        ? 'A carregar resultados…'
                                        : 'Sem resultados.'
                                }
                                getRowId={(row) => row.id}
                                grouping={groupBySupplier ? ['supplier'] : []}
                                loading={loading}
                            />
                        </div>
                    </ContextMenuTrigger>
                    <ContextMenuContent>
                        <ContextMenuGroup>
                            <ContextMenuItem disabled>
                                Clique direito numa linha para copiar
                            </ContextMenuItem>
                        </ContextMenuGroup>
                    </ContextMenuContent>
                </ContextMenu>
            )}

            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <span>Por página</span>
                    <ToggleGroup
                        type="single"
                        value={String(perPage)}
                        onValueChange={(value) => {
                            if (!value) {
                                return;
                            }

                            const size = Number(value) as FindingsPageSize;

                            if (FINDINGS_PAGE_SIZE_PRESETS.includes(size)) {
                                onPerPageChange(size);
                            }
                        }}
                        variant="outline"
                        size="sm"
                    >
                        {FINDINGS_PAGE_SIZE_PRESETS.map((size) => (
                            <ToggleGroupItem
                                key={size}
                                value={String(size)}
                                className="min-w-10"
                            >
                                {size}
                            </ToggleGroupItem>
                        ))}
                    </ToggleGroup>
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
