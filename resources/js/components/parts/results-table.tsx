import { type ColumnDef } from '@tanstack/react-table';
import { ArrowUpDown, Group } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import { Toggle } from '@/components/ui/toggle';

export type StockMode = 'quantity' | 'availability';

export type ResultRow = {
    variant: App.Data.PartVariant;
    supplier: string;
    stockMode: StockMode;
    price: number | null;
};

function stockLabel(row: ResultRow): string {
    if (row.stockMode === 'quantity') {
        return String(row.variant.availableQuantity);
    }

    return row.variant.inStock ? 'Disponível' : 'Indisponível';
}

function stockSortValue(row: ResultRow): number {
    if (row.stockMode === 'quantity') {
        return row.variant.availableQuantity;
    }

    return row.variant.inStock ? 1 : 0;
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

const columns: ColumnDef<ResultRow>[] = [
    {
        id: 'supplier',
        accessorFn: (row) => row.supplier,
        enableGrouping: true,
        aggregationFn: undefined,
        header: ({ column }) => (
            <SortableHeader
                label="Fornecedor"
                sorted={column.getIsSorted()}
                onToggle={() =>
                    column.toggleSorting(column.getIsSorted() === 'asc')
                }
            />
        ),
        cell: ({ getValue }) => (
            <span className="text-muted-foreground">{String(getValue())}</span>
        ),
    },
    {
        id: 'brand',
        accessorFn: (row) => row.variant.brandName,
        enableGrouping: false,
        aggregationFn: undefined,
        header: ({ column }) => (
            <SortableHeader
                label="Marca"
                sorted={column.getIsSorted()}
                onToggle={() =>
                    column.toggleSorting(column.getIsSorted() === 'asc')
                }
            />
        ),
        cell: ({ row }) => (
            <span className="font-medium">
                {row.original.variant.brandName}
            </span>
        ),
    },
    {
        id: 'article',
        accessorFn: (row) => row.variant.articleNumber,
        enableGrouping: false,
        aggregationFn: undefined,
        header: ({ column }) => (
            <SortableHeader
                label="Artigo"
                sorted={column.getIsSorted()}
                onToggle={() =>
                    column.toggleSorting(column.getIsSorted() === 'asc')
                }
            />
        ),
        cell: ({ row }) => row.original.variant.articleNumber,
    },
    {
        id: 'price',
        accessorFn: (row) => row.price,
        enableGrouping: false,
        aggregationFn: undefined,
        sortingFn: (a, b) => {
            const priceA = a.original.price;
            const priceB = b.original.price;

            if (priceA === null && priceB === null) {
                return 0;
            }

            if (priceA === null) {
                return 1;
            }

            if (priceB === null) {
                return -1;
            }

            return priceA - priceB;
        },
        header: ({ column }) => (
            <div className="text-right">
                <SortableHeader
                    label="Preço"
                    sorted={column.getIsSorted()}
                    onToggle={() =>
                        column.toggleSorting(column.getIsSorted() === 'asc')
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
        accessorFn: (row) => stockSortValue(row),
        enableGrouping: false,
        aggregationFn: undefined,
        header: ({ column }) => (
            <div className="text-right">
                <SortableHeader
                    label="Stock"
                    sorted={column.getIsSorted()}
                    onToggle={() =>
                        column.toggleSorting(column.getIsSorted() === 'asc')
                    }
                    align="right"
                />
            </div>
        ),
        cell: ({ row }) => (
            <span
                className={
                    row.original.variant.inStock
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
];

function rowId(row: ResultRow, index: number): string {
    return `${row.supplier}-${row.variant.brandName}-${row.variant.traderArticleNumber}-${index}`;
}

export function ResultsTable({ rows }: { rows: ResultRow[] }) {
    const [groupBySupplier, setGroupBySupplier] = useState(true);

    return (
        <div className="space-y-2">
            <div className="flex justify-end">
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

            <DataTable
                columns={columns}
                data={rows}
                emptyMessage="Sem resultados."
                getRowId={rowId}
                grouping={groupBySupplier ? ['supplier'] : []}
            />
        </div>
    );
}
