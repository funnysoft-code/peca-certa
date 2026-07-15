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
    price: number | null;
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
                    <TableHead className="text-right">Preço</TableHead>
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
                        <TableCell>{row.variant.articleNumber}</TableCell>
                        <TableCell className="text-right tabular-nums">
                            {row.price?.toFixed(2) ?? '–'}
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
