import {
    flexRender,
    getCoreRowModel,
    getExpandedRowModel,
    getGroupedRowModel,
    getSortedRowModel,
    useReactTable,
    type ColumnDef,
    type ExpandedState,
    type GroupingState,
    type SortingState,
} from '@tanstack/react-table';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

type DataTableProps<TData, TValue> = {
    columns: ColumnDef<TData, TValue>[];
    data: TData[];
    emptyMessage?: string;
    getRowId?: (originalRow: TData, index: number) => string;
    /** Column ids to group by (e.g. `['supplier']`). Empty = flat list. */
    grouping?: GroupingState;
};

export function DataTable<TData, TValue>({
    columns,
    data,
    emptyMessage = 'Sem resultados.',
    getRowId,
    grouping = [],
}: DataTableProps<TData, TValue>) {
    const [sorting, setSorting] = useState<SortingState>([]);
    const [expanded, setExpanded] = useState<ExpandedState>(true);
    const groupingKey = grouping.join('|');

    // Re-expand all groups when the grouping axes change.
    useEffect(() => {
        setExpanded(true);
    }, [groupingKey]);

    const table = useReactTable({
        data,
        columns,
        getRowId,
        getCoreRowModel: getCoreRowModel(),
        getSortedRowModel: getSortedRowModel(),
        getGroupedRowModel: getGroupedRowModel(),
        getExpandedRowModel: getExpandedRowModel(),
        onSortingChange: setSorting,
        onExpandedChange: setExpanded,
        groupedColumnMode: 'reorder',
        autoResetExpanded: false,
        state: {
            sorting,
            expanded,
            grouping,
        },
    });

    return (
        <div className="overflow-hidden rounded-md border">
            <Table>
                <TableHeader>
                    {table.getHeaderGroups().map((headerGroup) => (
                        <TableRow key={headerGroup.id}>
                            {headerGroup.headers.map((header) => (
                                <TableHead
                                    key={header.id}
                                    className={header.column.columnDef.meta?.headerClassName}
                                >
                                    {header.isPlaceholder
                                        ? null
                                        : flexRender(
                                              header.column.columnDef.header,
                                              header.getContext(),
                                          )}
                                </TableHead>
                            ))}
                        </TableRow>
                    ))}
                </TableHeader>
                <TableBody>
                    {table.getRowModel().rows.length > 0 ? (
                        table.getRowModel().rows.map((row) => (
                            <TableRow
                                key={row.id}
                                className={cn(row.getIsGrouped() && 'bg-muted/50 font-medium')}
                                data-state={row.getIsGrouped() ? 'grouped' : undefined}
                            >
                                {row.getVisibleCells().map((cell) => (
                                    <TableCell
                                        key={cell.id}
                                        className={cell.column.columnDef.meta?.cellClassName}
                                    >
                                        {row.getIsGrouped() ? (
                                            // Group header: only the grouping column, never
                                            // leaf values or column aggregates/totals.
                                            cell.getIsGrouped() ? (
                                                <button
                                                    type="button"
                                                    className="inline-flex items-center gap-1.5 text-left hover:underline"
                                                    onClick={row.getToggleExpandedHandler()}
                                                >
                                                    {row.getIsExpanded() ? (
                                                        <ChevronDown className="size-4 shrink-0" />
                                                    ) : (
                                                        <ChevronRight className="size-4 shrink-0" />
                                                    )}
                                                    <span>
                                                        {flexRender(
                                                            cell.column.columnDef.cell,
                                                            cell.getContext(),
                                                        )}
                                                    </span>
                                                    <span className="text-muted-foreground tabular-nums">
                                                        ({row.subRows.length})
                                                    </span>
                                                </button>
                                            ) : null
                                        ) : cell.getIsAggregated() ||
                                          cell.getIsPlaceholder() ? null : (
                                            flexRender(
                                                cell.column.columnDef.cell,
                                                cell.getContext(),
                                            )
                                        )}
                                    </TableCell>
                                ))}
                            </TableRow>
                        ))
                    ) : (
                        <TableRow>
                            <TableCell
                                colSpan={columns.length}
                                className="h-24 text-center text-muted-foreground"
                            >
                                {emptyMessage}
                            </TableCell>
                        </TableRow>
                    )}
                </TableBody>
            </Table>
        </div>
    );
}
