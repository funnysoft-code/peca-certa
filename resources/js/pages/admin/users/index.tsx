import { Form, Head, router, usePage } from '@inertiajs/react';
import { Mail, RotateCcw, Search, Trash2, UserPlus, Users } from 'lucide-react';
import { useMemo, useState } from 'react';
import { AdminSubnav } from '@/components/admin/admin-subnav';
import InputError from '@/components/input-error';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { initials, roleLabel } from '@/lib/admin-labels';
import { cn } from '@/lib/utils';
import { dashboard as adminDashboard } from '@/routes/admin';
import {
    destroy as destroyUser,
    index as usersIndex,
    resendInvite,
    restore as restoreUser,
    store as usersStore,
    updateRole,
} from '@/routes/admin/users';
import type { Auth } from '@/types';

type AdminUser = {
    id: string;
    name: string;
    email: string;
    status: 'pending' | 'active' | 'deleted';
    roles: string[];
    email_verified_at: string | null;
    created_at: string | null;
    deleted_at: string | null;
};

type Paginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    per_page: number;
};

type StatusFilter = 'all' | 'active' | 'pending' | 'deleted';

type Props = {
    users: Paginator<AdminUser>;
    roles: string[];
    filters: {
        status: StatusFilter;
    };
    counts: {
        all: number;
        active: number;
        pending: number;
        deleted: number;
    };
    can: {
        invite: boolean;
        manageRoles: boolean;
        delete: boolean;
        restore: boolean;
    };
};

export default function AdminUsersIndex({
    users,
    roles,
    filters,
    counts,
    can,
}: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const [inviteOpen, setInviteOpen] = useState(false);
    const [query, setQuery] = useState('');
    const statusFilter = filters.status;

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();

        if (q === '') {
            return users.data;
        }

        return users.data.filter(
            (user) =>
                user.name.toLowerCase().includes(q) ||
                user.email.toLowerCase().includes(q),
        );
    }, [users.data, query]);

    const setStatusFilter = (status: StatusFilter) => {
        router.get(
            usersIndex.url({
                query: status === 'all' ? {} : { status },
            }),
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const isDeletedView = statusFilter === 'deleted';

    return (
        <>
            <Head title="Utilizadores" />

            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
                <AdminSubnav />

                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="space-y-1">
                        <p className="text-xs font-medium tracking-[0.14em] text-brand uppercase">
                            Administração
                        </p>
                        <h1 className="font-display text-2xl font-semibold tracking-tight">
                            Utilizadores
                        </h1>
                        <p className="max-w-xl text-sm text-muted-foreground">
                            Operadores com acesso à plataforma.
                            {counts.pending > 0 && (
                                <span className="text-amber-200/90">
                                    {' '}
                                    {counts.pending} convite
                                    {counts.pending === 1 ? '' : 's'} pendente
                                    {counts.pending === 1 ? '' : 's'}.
                                </span>
                            )}
                            {counts.deleted > 0 && (
                                <span className="text-muted-foreground">
                                    {' '}
                                    {counts.deleted} removido
                                    {counts.deleted === 1 ? '' : 's'}.
                                </span>
                            )}
                        </p>
                    </div>

                    {can.invite && (
                        <Dialog open={inviteOpen} onOpenChange={setInviteOpen}>
                            <DialogTrigger asChild>
                                <Button className="shrink-0 gap-2">
                                    <UserPlus className="size-4" />
                                    Convidar
                                </Button>
                            </DialogTrigger>
                            <DialogContent className="sm:max-w-md">
                                <DialogHeader>
                                    <DialogTitle>Convidar operador</DialogTitle>
                                    <DialogDescription>
                                        Indique apenas nome e email. O convite
                                        define a palavra-passe; a função inicial
                                        é sempre Operador.
                                    </DialogDescription>
                                </DialogHeader>

                                <Form
                                    {...usersStore.form()}
                                    options={{ preserveScroll: true }}
                                    onSuccess={() => setInviteOpen(false)}
                                    className="grid gap-4"
                                    resetOnSuccess
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <div className="grid gap-2">
                                                <Label htmlFor="name">
                                                    Nome
                                                </Label>
                                                <Input
                                                    id="name"
                                                    name="name"
                                                    required
                                                    autoFocus
                                                    autoComplete="name"
                                                    placeholder="Nome completo"
                                                />
                                                <InputError
                                                    message={errors.name}
                                                />
                                            </div>
                                            <div className="grid gap-2">
                                                <Label htmlFor="email">
                                                    Email
                                                </Label>
                                                <Input
                                                    id="email"
                                                    name="email"
                                                    type="email"
                                                    required
                                                    autoComplete="email"
                                                    placeholder="operador@empresa.pt"
                                                />
                                                <InputError
                                                    message={errors.email}
                                                />
                                            </div>
                                            <DialogFooter>
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                    className="gap-2"
                                                >
                                                    {processing && <Spinner />}
                                                    Enviar convite
                                                </Button>
                                            </DialogFooter>
                                        </>
                                    )}
                                </Form>
                            </DialogContent>
                        </Dialog>
                    )}
                </div>

                <div className="overflow-hidden rounded-xl border border-border/70 bg-card">
                    <div className="flex flex-col gap-3 border-b border-border/60 p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div className="relative max-w-sm flex-1">
                            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                placeholder="Pesquisar nome ou email…"
                                className="h-9 pl-9"
                                aria-label="Pesquisar utilizadores"
                            />
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            {(
                                [
                                    ['all', 'Todos', counts.all],
                                    ['active', 'Ativos', counts.active],
                                    ['pending', 'Pendentes', counts.pending],
                                    ['deleted', 'Removidos', counts.deleted],
                                ] as const
                            ).map(([value, label, count]) => (
                                <Button
                                    key={value}
                                    type="button"
                                    size="sm"
                                    variant={
                                        statusFilter === value
                                            ? 'secondary'
                                            : 'ghost'
                                    }
                                    className={cn(
                                        'h-8',
                                        statusFilter === value &&
                                            'bg-brand/15 text-brand hover:bg-brand/20 hover:text-brand',
                                    )}
                                    onClick={() => setStatusFilter(value)}
                                >
                                    {label}
                                    <span className="ml-1 tabular-nums opacity-70">
                                        {count}
                                    </span>
                                </Button>
                            ))}
                            <span className="text-xs text-muted-foreground sm:ml-1">
                                {filtered.length}
                                {query ? ` de ${users.total}` : ''}
                            </span>
                        </div>
                    </div>

                    {users.data.length === 0 ? (
                        <Empty className="border-0 py-16">
                            <EmptyHeader>
                                <EmptyMedia variant="icon">
                                    <Users />
                                </EmptyMedia>
                                <EmptyTitle>
                                    {isDeletedView
                                        ? 'Nenhum removido'
                                        : 'Sem utilizadores'}
                                </EmptyTitle>
                                <EmptyDescription>
                                    {isDeletedView
                                        ? 'Não há utilizadores soft-deleted para restaurar.'
                                        : 'Convide o primeiro operador com nome e email.'}
                                </EmptyDescription>
                            </EmptyHeader>
                            {can.invite && !isDeletedView && (
                                <Button
                                    className="mt-2 gap-2"
                                    onClick={() => setInviteOpen(true)}
                                >
                                    <UserPlus className="size-4" />
                                    Convidar
                                </Button>
                            )}
                        </Empty>
                    ) : filtered.length === 0 ? (
                        <Empty className="border-0 py-14">
                            <EmptyHeader>
                                <EmptyTitle>Sem resultados</EmptyTitle>
                                <EmptyDescription>
                                    Ajuste a pesquisa ou o filtro de estado.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <>
                            {/* Desktop table */}
                            <div className="hidden md:block">
                                <Table>
                                    <TableHeader>
                                        <TableRow className="hover:bg-transparent">
                                            <TableHead className="pl-4">
                                                Operador
                                            </TableHead>
                                            <TableHead>Estado</TableHead>
                                            <TableHead>Função</TableHead>
                                            <TableHead className="pr-4 text-right">
                                                Ações
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {filtered.map((user) => {
                                            const isSelf =
                                                auth.user?.id === user.id;
                                            const role =
                                                user.roles[0] ?? 'user';

                                            return (
                                                <TableRow
                                                    key={user.id}
                                                    className={cn(
                                                        'hover:bg-muted/30',
                                                        user.status ===
                                                            'pending' &&
                                                            'bg-amber-500/[0.04]',
                                                    )}
                                                >
                                                    <TableCell className="pl-4">
                                                        <div className="flex items-center gap-3">
                                                            <Avatar
                                                                className={cn(
                                                                    'size-9 border border-border/60',
                                                                    isSelf &&
                                                                        'ring-2 ring-brand/40',
                                                                )}
                                                            >
                                                                <AvatarFallback className="bg-muted text-xs font-medium">
                                                                    {initials(
                                                                        user.name,
                                                                    )}
                                                                </AvatarFallback>
                                                            </Avatar>
                                                            <div className="min-w-0">
                                                                <div className="flex items-center gap-2">
                                                                    <span className="truncate font-medium">
                                                                        {
                                                                            user.name
                                                                        }
                                                                    </span>
                                                                    {isSelf && (
                                                                        <Badge
                                                                            variant="outline"
                                                                            className="h-5 border-brand/30 bg-brand/10 px-1.5 text-[10px] text-brand"
                                                                        >
                                                                            você
                                                                        </Badge>
                                                                    )}
                                                                </div>
                                                                <p className="truncate text-sm text-muted-foreground">
                                                                    {user.email}
                                                                </p>
                                                            </div>
                                                        </div>
                                                    </TableCell>
                                                    <TableCell>
                                                        <StatusBadge
                                                            status={user.status}
                                                        />
                                                    </TableCell>
                                                    <TableCell>
                                                        {user.status ===
                                                        'deleted' ? (
                                                            <span className="text-sm text-muted-foreground">
                                                                {roleLabel(
                                                                    role,
                                                                )}
                                                            </span>
                                                        ) : can.manageRoles ? (
                                                            <Select
                                                                value={role}
                                                                onValueChange={(
                                                                    next,
                                                                ) => {
                                                                    router.put(
                                                                        updateRole.url(
                                                                            user.id,
                                                                        ),
                                                                        {
                                                                            role: next,
                                                                        },
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    );
                                                                }}
                                                            >
                                                                <SelectTrigger
                                                                    className="h-8 w-[160px]"
                                                                    aria-label={`Função de ${user.name}`}
                                                                >
                                                                    <SelectValue
                                                                        placeholder={roleLabel(
                                                                            role,
                                                                        )}
                                                                    />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    {roles.map(
                                                                        (r) => (
                                                                            <SelectItem
                                                                                key={
                                                                                    r
                                                                                }
                                                                                value={
                                                                                    r
                                                                                }
                                                                            >
                                                                                {roleLabel(
                                                                                    r,
                                                                                )}
                                                                            </SelectItem>
                                                                        ),
                                                                    )}
                                                                </SelectContent>
                                                            </Select>
                                                        ) : (
                                                            <span className="text-sm">
                                                                {roleLabel(
                                                                    role,
                                                                )}
                                                            </span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="pr-4 text-right">
                                                        <UserRowActions
                                                            user={user}
                                                            isSelf={isSelf}
                                                            canInvite={
                                                                can.invite
                                                            }
                                                            canDelete={
                                                                can.delete
                                                            }
                                                            canRestore={
                                                                can.restore
                                                            }
                                                        />
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            </div>

                            {/* Mobile cards */}
                            <div className="divide-y divide-border/60 md:hidden">
                                {filtered.map((user) => {
                                    const isSelf = auth.user?.id === user.id;
                                    const role = user.roles[0] ?? 'user';

                                    return (
                                        <div
                                            key={user.id}
                                            className={cn(
                                                'flex flex-col gap-3 p-4',
                                                user.status === 'pending' &&
                                                    'bg-amber-500/[0.04]',
                                            )}
                                        >
                                            <div className="flex items-start gap-3">
                                                <Avatar className="size-10 border border-border/60">
                                                    <AvatarFallback className="bg-muted text-xs font-medium">
                                                        {initials(user.name)}
                                                    </AvatarFallback>
                                                </Avatar>
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="font-medium">
                                                            {user.name}
                                                        </span>
                                                        {isSelf && (
                                                            <Badge
                                                                variant="outline"
                                                                className="h-5 border-brand/30 bg-brand/10 px-1.5 text-[10px] text-brand"
                                                            >
                                                                você
                                                            </Badge>
                                                        )}
                                                        <StatusBadge
                                                            status={user.status}
                                                        />
                                                    </div>
                                                    <p className="truncate text-sm text-muted-foreground">
                                                        {user.email}
                                                    </p>
                                                </div>
                                            </div>
                                            <div className="flex flex-wrap items-center justify-between gap-3">
                                                {user.status !== 'deleted' &&
                                                can.manageRoles ? (
                                                    <Select
                                                        value={role}
                                                        onValueChange={(
                                                            next,
                                                        ) => {
                                                            router.put(
                                                                updateRole.url(
                                                                    user.id,
                                                                ),
                                                                { role: next },
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            );
                                                        }}
                                                    >
                                                        <SelectTrigger className="h-8 w-[160px]">
                                                            <SelectValue
                                                                placeholder={roleLabel(
                                                                    role,
                                                                )}
                                                            />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {roles.map((r) => (
                                                                <SelectItem
                                                                    key={r}
                                                                    value={r}
                                                                >
                                                                    {roleLabel(
                                                                        r,
                                                                    )}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                ) : (
                                                    <span className="text-sm">
                                                        {roleLabel(role)}
                                                    </span>
                                                )}
                                                <UserRowActions
                                                    user={user}
                                                    isSelf={isSelf}
                                                    canInvite={can.invite}
                                                    canDelete={can.delete}
                                                    canRestore={can.restore}
                                                    compact={false}
                                                />
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </>
                    )}
                </div>
            </div>
        </>
    );
}

function StatusBadge({ status }: { status: 'pending' | 'active' | 'deleted' }) {
    if (status === 'pending') {
        return (
            <Badge
                variant="outline"
                className="border-amber-500/40 bg-amber-500/10 text-amber-200"
            >
                Pendente
            </Badge>
        );
    }

    if (status === 'deleted') {
        return (
            <Badge
                variant="outline"
                className="border-muted-foreground/30 bg-muted/40 text-muted-foreground"
            >
                Removido
            </Badge>
        );
    }

    return (
        <Badge
            variant="outline"
            className="border-brand/40 bg-brand/10 text-brand"
        >
            Ativo
        </Badge>
    );
}

function UserRowActions({
    user,
    isSelf,
    canInvite,
    canDelete,
    canRestore,
    compact = true,
}: {
    user: AdminUser;
    isSelf: boolean;
    canInvite: boolean;
    canDelete: boolean;
    canRestore: boolean;
    compact?: boolean;
}) {
    const [confirmOpen, setConfirmOpen] = useState(false);
    const isDeleted = user.status === 'deleted';
    const showResend = canInvite && user.status === 'pending';
    const showDelete = canDelete && !isSelf && !isDeleted;
    const showRestore = canRestore && isDeleted;

    if (!showResend && !showDelete && !showRestore) {
        return null;
    }

    return (
        <div className="flex items-center justify-end gap-1.5">
            {showResend && (
                <Form
                    {...resendInvite.form(user.id)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant={compact ? 'ghost' : 'outline'}
                            size="sm"
                            disabled={processing}
                            className="gap-1.5 text-muted-foreground hover:text-foreground"
                        >
                            {processing ? (
                                <Spinner />
                            ) : (
                                <Mail className="size-3.5" />
                            )}
                            Reenviar
                        </Button>
                    )}
                </Form>
            )}

            {showRestore && (
                <Form
                    {...restoreUser.form(user.id)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant={compact ? 'ghost' : 'outline'}
                            size="sm"
                            disabled={processing}
                            className="gap-1.5 text-brand hover:bg-brand/10 hover:text-brand"
                        >
                            {processing ? (
                                <Spinner />
                            ) : (
                                <RotateCcw className="size-3.5" />
                            )}
                            Restaurar
                        </Button>
                    )}
                </Form>
            )}

            {showDelete && (
                <Dialog open={confirmOpen} onOpenChange={setConfirmOpen}>
                    <DialogTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="gap-1.5 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                        >
                            <Trash2 className="size-3.5" />
                            Remover
                        </Button>
                    </DialogTrigger>
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>Remover utilizador?</DialogTitle>
                            <DialogDescription>
                                <span className="font-medium text-foreground">
                                    {user.name}
                                </span>{' '}
                                ({user.email}) será removido da equipa e deixa
                                de poder aceder. Pode restaurá-lo mais tarde em
                                Removidos, ou convidar de novo o mesmo email.
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter className="gap-2 sm:gap-0">
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    Cancelar
                                </Button>
                            </DialogClose>
                            <Form
                                {...destroyUser.form(user.id)}
                                options={{ preserveScroll: true }}
                                onSuccess={() => setConfirmOpen(false)}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        disabled={processing}
                                        className="gap-1.5"
                                    >
                                        {processing && <Spinner />}
                                        Remover
                                    </Button>
                                )}
                            </Form>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            )}
        </div>
    );
}

AdminUsersIndex.layout = {
    breadcrumbs: [
        {
            title: 'Admin',
            href: adminDashboard(),
        },
        {
            title: 'Utilizadores',
            href: usersIndex(),
        },
    ],
};
