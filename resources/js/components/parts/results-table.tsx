import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

export type StockMode = 'quantity' | 'availability';

export function ResultsTable({
    variants,
    stockMode = 'quantity',
}: {
    variants: App.Data.PartVariant[];
    stockMode?: StockMode;
}) {
    if (variants.length === 0) {
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
                    <TableHead>Marca</TableHead>
                    <TableHead>Artigo</TableHead>
                    <TableHead className="text-right">Compra</TableHead>
                    <TableHead className="text-right">PVP</TableHead>
                    <TableHead className="text-right">Stock</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {variants.map((v) => (
                    <TableRow key={`${v.brandName}-${v.articleNumber}`}>
                        <TableCell className="font-medium">
                            {v.brandName}
                        </TableCell>
                        <TableCell>{v.articleNumber}</TableCell>
                        <TableCell className="text-right tabular-nums">
                            {v.purchasePrice?.toFixed(2) ?? '—'}
                        </TableCell>
                        <TableCell className="text-right tabular-nums">
                            {v.retailPrice?.toFixed(2) ?? '—'}
                        </TableCell>
                        <TableCell className="text-right tabular-nums">
                            <span
                                className={
                                    v.inStock
                                        ? 'text-emerald-600'
                                        : 'text-muted-foreground'
                                }
                            >
                                {stockMode === 'quantity'
                                    ? v.availableQuantity
                                    : v.inStock
                                      ? 'Disponível'
                                      : 'Indisponível'}
                            </span>
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}
