import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

export function ResultsTable({
    variants,
}: {
    variants: App.Data.PartVariant[];
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
                                {v.availableQuantity}
                            </span>
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}
