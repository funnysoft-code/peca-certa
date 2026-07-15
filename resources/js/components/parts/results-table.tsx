import { ExternalLink } from 'lucide-react';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

export type StockMode = 'quantity' | 'availability';

export type ResultRow = {
    variant: App.Data.PartVariant;
    supplier: string;
    stockMode: StockMode;
};

function stockLabel(row: ResultRow): string {
    if (row.stockMode === 'quantity') {
        return String(row.variant.availableQuantity);
    }

    return row.variant.inStock ? 'Disponível' : 'Indisponível';
}

export function ResultsTable({ rows }: { rows: ResultRow[] }) {
    if (rows.length === 0) {
        return (
            <p className="py-8 text-center text-sm text-muted-foreground">
                Sem resultados.
            </p>
        );
    }

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Fornecedor</TableHead>
                    <TableHead>Marca</TableHead>
                    <TableHead>Artigo</TableHead>
                    <TableHead className="text-right">Compra</TableHead>
                    <TableHead className="text-right">PVP</TableHead>
                    <TableHead className="text-right">Stock</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.map((row) => (
                    <TableRow
                        key={`${row.supplier}-${row.variant.brandName}-${row.variant.traderArticleNumber}`}
                    >
                        <TableCell className="text-muted-foreground">
                            {row.supplier}
                        </TableCell>
                        <TableCell className="font-medium">
                            {row.variant.brandName}
                        </TableCell>
                        <TableCell>
                            {row.variant.url ? (
                                <a
                                    href={row.variant.url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex items-center gap-1 text-primary underline-offset-4 hover:underline"
                                    title={`Abrir em ${row.supplier}`}
                                >
                                    {row.variant.articleNumber}
                                    <ExternalLink className="size-3.5 opacity-70" />
                                </a>
                            ) : (
                                row.variant.articleNumber
                            )}
                        </TableCell>
                        <TableCell className="text-right tabular-nums">
                            {row.variant.purchasePrice?.toFixed(2) ?? '–'}
                        </TableCell>
                        <TableCell className="text-right tabular-nums">
                            {row.variant.retailPrice?.toFixed(2) ?? '–'}
                        </TableCell>
                        <TableCell className="text-right tabular-nums">
                            <span
                                className={
                                    row.variant.inStock
                                        ? 'text-emerald-600'
                                        : 'text-muted-foreground'
                                }
                            >
                                {stockLabel(row)}
                            </span>
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}
