import { Head, router } from '@inertiajs/react';
import { KeyRound, Loader2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Label } from '@/components/ui/label';
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
    index as rolesIndex,
    update as rolesUpdate,
} from '@/routes/admin/roles';

type RoleRow = {
    id: number;
    name: string;
    permissions: string[];
};

type Props = {
    roles: RoleRow[];
    permissions: string[];
    can: {
        manage: boolean;
    };
};

export default function AdminRolesIndex({ roles, permissions, can }: Props) {
    const [draft, setDraft] = useState<Record<number, string[]>>(() =>
        Object.fromEntries(
            roles.map((role) => [role.id, [...role.permissions]]),
        ),
    );
    const [savingRoleId, setSavingRoleId] = useState<number | null>(null);

    const grouped = useMemo(() => {
        const groups = new Map<string, string[]>();

        for (const permission of permissions) {
            const [resource] = permission.split('.');
            const key = resource ?? 'other';
            const list = groups.get(key) ?? [];
            list.push(permission);
            groups.set(key, list);
        }

        return [...groups.entries()];
    }, [permissions]);

    const isDirty = (role: RoleRow): boolean => {
        const current = draft[role.id] ?? [];
        const original = role.permissions;

        if (current.length !== original.length) {
            return true;
        }

        const set = new Set(original);

        return current.some((name) => !set.has(name));
    };

    const toggle = (roleId: number, permission: string, checked: boolean) => {
        setDraft((prev) => {
            const current = new Set(prev[roleId] ?? []);

            if (checked) {
                current.add(permission);
            } else {
                current.delete(permission);
            }

            return { ...prev, [roleId]: [...current].sort() };
        });
    };

    const save = (role: RoleRow) => {
        setSavingRoleId(role.id);
        router.put(
            rolesUpdate.url(role.id),
            { permissions: draft[role.id] ?? [] },
            {
                preserveScroll: true,
                onFinish: () => setSavingRoleId(null),
            },
        );
    };

    return (
        <>
            <Head title="Funções e permissões" />

            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
                <div>
                    <p className="text-xs font-medium tracking-[0.14em] text-brand uppercase">
                        Administração
                    </p>
                    <Heading
                        title="Funções e permissões"
                        description="Matriz role ↔ permission. Alterações invalidam a cache do Spatie. O seeder continua a ser a SSOT de bootstrap."
                    />
                </div>

                {roles.length === 0 || permissions.length === 0 ? (
                    <Empty className="border border-dashed border-border/80">
                        <EmptyHeader>
                            <EmptyMedia variant="icon">
                                <KeyRound />
                            </EmptyMedia>
                            <EmptyTitle>Sem matriz</EmptyTitle>
                            <EmptyDescription>
                                Execute o seeder de roles e permissões para
                                popular a matriz.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                ) : (
                    <div className="flex flex-col gap-6">
                        {roles.map((role) => {
                            const dirty = isDirty(role);
                            const selected = new Set(draft[role.id] ?? []);

                            return (
                                <Card
                                    key={role.id}
                                    className="overflow-hidden border-border/80"
                                >
                                    <CardHeader className="flex flex-row items-start justify-between gap-4 border-b border-border/60">
                                        <div>
                                            <CardTitle className="flex items-center gap-2 font-display text-lg">
                                                {role.name}
                                                <Badge
                                                    variant="outline"
                                                    className="font-sans text-xs font-normal"
                                                >
                                                    {selected.size} permissões
                                                </Badge>
                                            </CardTitle>
                                            <CardDescription>
                                                {role.name === 'admin'
                                                    ? 'Acesso completo da plataforma (re-sincronizado no seeder).'
                                                    : 'Operador de oficina — sem UI de administração.'}
                                            </CardDescription>
                                        </div>
                                        {can.manage && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                disabled={
                                                    !dirty ||
                                                    savingRoleId === role.id
                                                }
                                                onClick={() => save(role)}
                                                className="gap-2"
                                            >
                                                {savingRoleId === role.id && (
                                                    <Loader2 className="size-3.5 animate-spin" />
                                                )}
                                                Guardar
                                            </Button>
                                        )}
                                    </CardHeader>
                                    <CardContent className="p-0">
                                        <div className="overflow-x-auto">
                                            <Table>
                                                <TableHeader>
                                                    <TableRow>
                                                        <TableHead className="w-40">
                                                            Recurso
                                                        </TableHead>
                                                        <TableHead>
                                                            Permissões
                                                        </TableHead>
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody>
                                                    {grouped.map(
                                                        ([resource, names]) => (
                                                            <TableRow
                                                                key={`${role.id}-${resource}`}
                                                            >
                                                                <TableCell className="align-top font-medium capitalize">
                                                                    {resource}
                                                                </TableCell>
                                                                <TableCell>
                                                                    <div className="flex flex-wrap gap-x-4 gap-y-2">
                                                                        {names.map(
                                                                            (
                                                                                permission,
                                                                            ) => {
                                                                                const checked =
                                                                                    selected.has(
                                                                                        permission,
                                                                                    );
                                                                                const id = `${role.id}-${permission}`;

                                                                                return (
                                                                                    <div
                                                                                        key={
                                                                                            permission
                                                                                        }
                                                                                        className="flex items-center gap-2"
                                                                                    >
                                                                                        <Checkbox
                                                                                            id={
                                                                                                id
                                                                                            }
                                                                                            checked={
                                                                                                checked
                                                                                            }
                                                                                            disabled={
                                                                                                !can.manage
                                                                                            }
                                                                                            onCheckedChange={(
                                                                                                value,
                                                                                            ) =>
                                                                                                toggle(
                                                                                                    role.id,
                                                                                                    permission,
                                                                                                    value ===
                                                                                                        true,
                                                                                                )
                                                                                            }
                                                                                        />
                                                                                        <Label
                                                                                            htmlFor={
                                                                                                id
                                                                                            }
                                                                                            className="font-mono text-xs font-normal text-muted-foreground"
                                                                                        >
                                                                                            {
                                                                                                permission
                                                                                            }
                                                                                        </Label>
                                                                                    </div>
                                                                                );
                                                                            },
                                                                        )}
                                                                    </div>
                                                                </TableCell>
                                                            </TableRow>
                                                        ),
                                                    )}
                                                </TableBody>
                                            </Table>
                                        </div>
                                    </CardContent>
                                </Card>
                            );
                        })}
                    </div>
                )}
            </div>
        </>
    );
}

AdminRolesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Admin',
            href: adminDashboard(),
        },
        {
            title: 'Funções',
            href: rolesIndex(),
        },
    ],
};
