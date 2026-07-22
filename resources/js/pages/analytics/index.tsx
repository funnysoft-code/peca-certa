import { Head, router } from '@inertiajs/react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    Pie,
    PieChart,
    XAxis,
    YAxis,
} from 'recharts';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
    type ChartConfig,
} from '@/components/ui/chart';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { index as analyticsIndex } from '@/routes/analytics';

type ChartPoint = { name: string; value: number };

type RankedRow = {
    label: string;
    count: number;
    in_stock_count: number;
    stock_rate: number;
    min_price: number | null;
    median_price: number | null;
    p25_price: number | null;
};

type Analytics = {
    range_days: number;
    from: string;
    to: string;
    scorecards: {
        findings: number;
        in_stock: number;
        stock_hit_rate: number;
        brands: number;
        suppliers: number;
        with_price: number;
    };
    suppliers_chart: ChartPoint[];
    brands_chart: ChartPoint[];
    stock_chart: ChartPoint[];
    ranked_brands: RankedRow[];
    ranked_suppliers: RankedRow[];
    head_to_head: {
        pairs: number;
        autodelta_wins: number;
        autozitania_wins: number;
        ties: number;
        autodelta_win_rate: number;
        autozitania_win_rate: number;
    };
};

type Props = {
    analytics: Analytics;
    range: number;
    ranges: number[];
};

const suppliersConfig = {
    value: { label: 'Aparições', color: 'var(--chart-1)' },
} satisfies ChartConfig;

const brandsConfig = {
    value: { label: 'Aparições', color: 'var(--chart-2)' },
} satisfies ChartConfig;

const stockConfig = {
    value: { label: 'Stock', color: 'var(--chart-3)' },
} satisfies ChartConfig;

const STOCK_COLORS = ['var(--chart-2)', 'var(--chart-4)'];

function formatPrice(value: number | null): string {
    if (value === null) {
        return '–';
    }

    return value.toFixed(2);
}

function Scorecard({
    title,
    value,
    hint,
}: {
    title: string;
    value: string;
    hint?: string;
}) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <CardDescription>{title}</CardDescription>
                <CardTitle className="font-display text-2xl tabular-nums">
                    {value}
                </CardTitle>
            </CardHeader>
            {hint ? (
                <CardContent>
                    <p className="text-xs text-muted-foreground">{hint}</p>
                </CardContent>
            ) : null}
        </Card>
    );
}

export default function AnalyticsIndex({ analytics, range, ranges }: Props) {
    const empty = analytics.scorecards.findings === 0;
    const headToHead = analytics.head_to_head;

    function setRange(next: string): void {
        const days = Number(next);

        if (!ranges.includes(days)) {
            return;
        }

        router.get(
            analyticsIndex.url({ query: { range: days } }),
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <>
            <Head title="Análises de procurement" />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div className="flex flex-col gap-1">
                        <h1 className="font-display text-xl font-semibold tracking-tight">
                            Análises de procurement
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Visão da oficina sobre marcas, fornecedores, stock e
                            preço de decisão (todos os operadores).
                        </p>
                    </div>
                    <ToggleGroup
                        type="single"
                        value={String(range)}
                        onValueChange={setRange}
                        variant="outline"
                        size="sm"
                    >
                        {ranges.map((days) => (
                            <ToggleGroupItem
                                key={days}
                                value={String(days)}
                                className="min-w-14"
                            >
                                {days}d
                            </ToggleGroupItem>
                        ))}
                    </ToggleGroup>
                </div>

                {empty ? (
                    <Empty className="border border-dashed">
                        <EmptyHeader>
                            <EmptyTitle>Sem findings neste período</EmptyTitle>
                            <EmptyDescription>
                                Execute pesquisas de identificação ou peças para
                                gerar dados de procurement.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <Scorecard
                                title="Findings"
                                value={String(analytics.scorecards.findings)}
                                hint="Linhas normalizadas no período"
                            />
                            <Scorecard
                                title="Taxa em stock"
                                value={`${analytics.scorecards.stock_hit_rate}%`}
                                hint={`${analytics.scorecards.in_stock} com stock`}
                            />
                            <Scorecard
                                title="Marcas"
                                value={String(analytics.scorecards.brands)}
                            />
                            <Scorecard
                                title="Vitória Delta (mais barato em stock)"
                                value={`${headToHead.autodelta_win_rate}%`}
                                hint={`${headToHead.pairs} pares comparáveis · Zitânia ${headToHead.autozitania_win_rate}%`}
                            />
                        </div>

                        <div className="grid gap-4 lg:grid-cols-3">
                            <Card className="lg:col-span-1">
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Por fornecedor
                                    </CardTitle>
                                    <CardDescription>
                                        Aparições no período
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <ChartContainer
                                        config={suppliersConfig}
                                        className="aspect-[4/3] w-full"
                                    >
                                        <BarChart
                                            data={analytics.suppliers_chart}
                                            accessibilityLayer
                                        >
                                            <CartesianGrid vertical={false} />
                                            <XAxis
                                                dataKey="name"
                                                tickLine={false}
                                                axisLine={false}
                                            />
                                            <YAxis
                                                allowDecimals={false}
                                                tickLine={false}
                                                axisLine={false}
                                            />
                                            <ChartTooltip
                                                content={
                                                    <ChartTooltipContent />
                                                }
                                            />
                                            <Bar
                                                dataKey="value"
                                                fill="var(--color-value)"
                                                radius={4}
                                            />
                                        </BarChart>
                                    </ChartContainer>
                                </CardContent>
                            </Card>

                            <Card className="lg:col-span-1">
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Top marcas
                                    </CardTitle>
                                    <CardDescription>
                                        Mais frequentes
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <ChartContainer
                                        config={brandsConfig}
                                        className="aspect-[4/3] w-full"
                                    >
                                        <BarChart
                                            data={analytics.brands_chart}
                                            layout="vertical"
                                            accessibilityLayer
                                        >
                                            <CartesianGrid horizontal={false} />
                                            <XAxis
                                                type="number"
                                                allowDecimals={false}
                                                tickLine={false}
                                                axisLine={false}
                                            />
                                            <YAxis
                                                type="category"
                                                dataKey="name"
                                                width={80}
                                                tickLine={false}
                                                axisLine={false}
                                            />
                                            <ChartTooltip
                                                content={
                                                    <ChartTooltipContent />
                                                }
                                            />
                                            <Bar
                                                dataKey="value"
                                                fill="var(--color-value)"
                                                radius={4}
                                            />
                                        </BarChart>
                                    </ChartContainer>
                                </CardContent>
                            </Card>

                            <Card className="lg:col-span-1">
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Stock
                                    </CardTitle>
                                    <CardDescription>
                                        Em stock vs sem stock
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <ChartContainer
                                        config={stockConfig}
                                        className="aspect-[4/3] w-full"
                                    >
                                        <PieChart accessibilityLayer>
                                            <ChartTooltip
                                                content={
                                                    <ChartTooltipContent nameKey="name" />
                                                }
                                            />
                                            <Pie
                                                data={analytics.stock_chart}
                                                dataKey="value"
                                                nameKey="name"
                                                innerRadius={50}
                                            >
                                                {analytics.stock_chart.map(
                                                    (entry, index) => (
                                                        <Cell
                                                            key={entry.name}
                                                            fill={
                                                                STOCK_COLORS[
                                                                    index %
                                                                        STOCK_COLORS.length
                                                                ]
                                                            }
                                                        />
                                                    ),
                                                )}
                                            </Pie>
                                        </PieChart>
                                    </ChartContainer>
                                </CardContent>
                            </Card>
                        </div>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Marcas ranqueadas
                                </CardTitle>
                                <CardDescription>
                                    Aparições, stock e preços de decisão (min /
                                    p25 / mediana)
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="overflow-hidden rounded-md border">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Marca</TableHead>
                                                <TableHead className="text-right">
                                                    Aparições
                                                </TableHead>
                                                <TableHead className="text-right">
                                                    Stock %
                                                </TableHead>
                                                <TableHead className="text-right">
                                                    Min
                                                </TableHead>
                                                <TableHead className="text-right">
                                                    P25
                                                </TableHead>
                                                <TableHead className="text-right">
                                                    Mediana
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {analytics.ranked_brands.map(
                                                (row) => (
                                                    <TableRow key={row.label}>
                                                        <TableCell className="font-medium">
                                                            {row.label}
                                                        </TableCell>
                                                        <TableCell className="text-right tabular-nums">
                                                            {row.count}
                                                        </TableCell>
                                                        <TableCell className="text-right tabular-nums">
                                                            {row.stock_rate}%
                                                        </TableCell>
                                                        <TableCell className="text-right tabular-nums">
                                                            {formatPrice(
                                                                row.min_price,
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="text-right tabular-nums">
                                                            {formatPrice(
                                                                row.p25_price,
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="text-right tabular-nums">
                                                            {formatPrice(
                                                                row.median_price,
                                                            )}
                                                        </TableCell>
                                                    </TableRow>
                                                ),
                                            )}
                                        </TableBody>
                                    </Table>
                                </div>
                            </CardContent>
                        </Card>
                    </>
                )}
            </div>
        </>
    );
}

AnalyticsIndex.layout = {
    breadcrumbs: [{ title: 'Análises', href: analyticsIndex() }],
};
