import { Form, Head, router, usePage } from '@inertiajs/react';
import { Mail, Shield, UserPlus, Users } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
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
import { dashboard as adminDashboard } from '@/routes/admin';
import {
    index as usersIndex,
    resendInvite,
    store as usersStore,
    updateRole,
} from '@/routes/admin/users';
import type { Auth } from '@/types';

type AdminUser = {
    id: string;
    name: string;
    email: string;
    status: 'pending' | 'active';
    roles: string[];
    email_verified_at: string | null;
    created_at: string | null;
};

type Paginator<T> = {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
};

type Props = {
    users: Paginator<AdminUser>;
    roles: string[];
    can: {
        invite: boolean;
        manageRoles: boolean;
    };
};

export default function AdminUsersIndex({ users, roles, can }: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const [inviteOpen, setInviteOpen] = useState(false);

    return (
        <>
            <Head title="Utilizadores" />

            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="text-xs font-medium tracking-[0.14em] text-brand uppercase">
                            Administração
                        </p>
                        <Heading
                            title="Utilizadores"
                            description="Convide operadores e gerencie funções. O convite não define palavra-passe nem função (sempre user)."
                        />
                    </div>

                    {can.invite && (
                        <Dialog open={inviteOpen} onOpenChange={setInviteOpen}>
                            <DialogTrigger asChild>
                                <Button className="gap-2">
                                    <UserPlus className="size-4" />
                                    Convidar
                                </Button>
                            </DialogTrigger>
                            <DialogContent className="sm:max-w-md">
                                <DialogHeader>
                                    <DialogTitle>
                                        Convidar utilizador
                                    </DialogTitle>
                                    <DialogDescription>
                                        Nome e email apenas. Será enviada a
                                        ligação para definir a palavra-passe.
                                        Função inicial: user.
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

                <Card className="border-border/80">
                    <CardHeader className="border-b border-border/60">
                        <CardTitle className="text-base">Equipa</CardTitle>
                        <CardDescription>
                            {users.meta.total}{' '}
                            {users.meta.total === 1
                                ? 'utilizador'
                                : 'utilizadores'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="p-0">
                        {users.data.length === 0 ? (
                            <Empty className="border-0 py-16">
                                <EmptyHeader>
                                    <EmptyMedia variant="icon">
                                        <Users />
                                    </EmptyMedia>
                                    <EmptyTitle>Sem utilizadores</EmptyTitle>
                                    <EmptyDescription>
                                        Convide o primeiro operador com nome e
                                        email.
                                    </EmptyDescription>
                                </EmptyHeader>
                            </Empty>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Nome</TableHead>
                                        <TableHead>Email</TableHead>
                                        <TableHead>Estado</TableHead>
                                        <TableHead>Função</TableHead>
                                        <TableHead className="text-right">
                                            Ações
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {users.data.map((user) => (
                                        <TableRow key={user.id}>
                                            <TableCell className="font-medium">
                                                {user.name}
                                                {auth.user?.id === user.id && (
                                                    <span className="ml-2 text-xs text-muted-foreground">
                                                        (você)
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {user.email}
                                            </TableCell>
                                            <TableCell>
                                                {user.status === 'pending' ? (
                                                    <Badge
                                                        variant="outline"
                                                        className="border-amber-500/40 bg-amber-500/10 text-amber-200"
                                                    >
                                                        Convite pendente
                                                    </Badge>
                                                ) : (
                                                    <Badge
                                                        variant="outline"
                                                        className="border-brand/40 bg-brand/10 text-brand"
                                                    >
                                                        Ativo
                                                    </Badge>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {can.manageRoles ? (
                                                    <Select
                                                        value={
                                                            user.roles[0] ??
                                                            'user'
                                                        }
                                                        onValueChange={(
                                                            role,
                                                        ) => {
                                                            router.put(
                                                                updateRole.url(
                                                                    user.id,
                                                                ),
                                                                { role },
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            );
                                                        }}
                                                    >
                                                        <SelectTrigger
                                                            className="h-8 w-[120px]"
                                                            aria-label={`Função de ${user.name}`}
                                                        >
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {roles.map(
                                                                (role) => (
                                                                    <SelectItem
                                                                        key={
                                                                            role
                                                                        }
                                                                        value={
                                                                            role
                                                                        }
                                                                    >
                                                                        {role}
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectContent>
                                                    </Select>
                                                ) : (
                                                    <span className="inline-flex items-center gap-1 text-sm">
                                                        <Shield className="size-3.5 text-muted-foreground" />
                                                        {user.roles[0] ?? '—'}
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {can.invite &&
                                                    user.status ===
                                                        'pending' && (
                                                        <Form
                                                            {...resendInvite.form(
                                                                user.id,
                                                            )}
                                                            options={{
                                                                preserveScroll: true,
                                                            }}
                                                        >
                                                            {({
                                                                processing,
                                                            }) => (
                                                                <Button
                                                                    type="submit"
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    disabled={
                                                                        processing
                                                                    }
                                                                    className="gap-1.5"
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
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
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
