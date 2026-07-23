import { Head, router } from '@inertiajs/react';
import { KeyRound, Loader2, RotateCcw } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { AdminSubnav } from '@/components/admin/admin-subnav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    permissionActionLabel,
    permissionLabel,
    resourceLabel,
    roleLabel,
} from '@/lib/admin-labels';
import { cn } from '@/lib/utils';
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
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (saving) {
            return;
        }

        setDraft(
            Object.fromEntries(
                roles.map((role) => [role.id, [...role.permissions]]),
            ),
        );
    }, [roles, saving]);

    const grouped = useMemo(() => {
        const groups = new Map<string, string[]>();

        for (const permission of permissions) {
            const [resource] = permission.split('.');
            const key = resource ?? 'other';
            const list = groups.get(key) ?? [];
            list.push(permission);
            groups.set(key, list);
        }

        return [...groups.entries()].map(([resource, names]) => ({
            resource,
            permissions: names.sort(),
        }));
    }, [permissions]);

    const dirtyRoles = useMemo(() => {
        return roles.filter((role) => {
            const current = draft[role.id] ?? [];
            const original = role.permissions;

            if (current.length !== original.length) {
                return true;
            }

            const set = new Set(original);

            return current.some((name) => !set.has(name));
        });
    }, [roles, draft]);

    const isDirty = dirtyRoles.length > 0;

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

    const toggleResourceForRole = (
        roleId: number,
        resourcePermissions: string[],
        checked: boolean,
    ) => {
        setDraft((prev) => {
            const current = new Set(prev[roleId] ?? []);

            for (const permission of resourcePermissions) {
                if (checked) {
                    current.add(permission);
                } else {
                    current.delete(permission);
                }
            }

            return { ...prev, [roleId]: [...current].sort() };
        });
    };

    const discard = () => {
        setDraft(
            Object.fromEntries(
                roles.map((role) => [role.id, [...role.permissions]]),
            ),
        );
    };

    const saveAll = () => {
        if (dirtyRoles.length === 0) {
            return;
        }

        setSaving(true);

        const chain = dirtyRoles.reduce(
            (promise, role) =>
                promise.then(
                    () =>
                        new Promise<void>((resolve, reject) => {
                            router.put(
                                rolesUpdate.url(role.id),
                                {
                                    permissions: draft[role.id] ?? [],
                                },
                                {
                                    preserveScroll: true,
                                    onSuccess: () => resolve(),
                                    onError: () => reject(),
                                },
                            );
                        }),
                ),
            Promise.resolve(),
        );

        void chain.finally(() => setSaving(false));
    };

    return (
        <>
            <Head title="Funções e permissões" />

            <div
                className={cn(
                    'mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6',
                    isDirty && can.manage && 'pb-28',
                )}
            >
                <AdminSubnav />

                <div className="space-y-1">
                    <p className="text-xs font-medium tracking-[0.14em] text-brand uppercase">
                        Administração
                    </p>
                    <h1 className="font-display text-2xl font-semibold tracking-tight">
                        Funções e permissões
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        Defina o que cada função pode fazer. As alterações
                        atualizam a cache de permissões de imediato.
                    </p>
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
                    <div className="overflow-hidden rounded-xl border border-border/70 bg-card">
                        <div className="flex flex-wrap items-center gap-3 border-b border-border/60 px-4 py-3">
                            {roles.map((role) => (
                                <div
                                    key={role.id}
                                    className="flex items-center gap-2"
                                >
                                    <span className="font-display text-sm font-semibold">
                                        {roleLabel(role.name)}
                                    </span>
                                    <Badge
                                        variant="outline"
                                        className="font-sans text-[10px] font-normal"
                                    >
                                        {(draft[role.id] ?? []).length}{' '}
                                        permissões
                                    </Badge>
                                    {role.name === 'admin' && (
                                        <span className="text-[11px] text-muted-foreground">
                                            re-sincronizado no seeder
                                        </span>
                                    )}
                                </div>
                            ))}
                        </div>

                        {/* Desktop matrix */}
                        <div className="hidden overflow-x-auto md:block">
                            <table className="w-full min-w-[640px] text-sm">
                                <thead>
                                    <tr className="border-b border-border/60 bg-muted/20">
                                        <th className="px-4 py-3 text-left text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                            Recurso / permissão
                                        </th>
                                        {roles.map((role) => (
                                            <th
                                                key={role.id}
                                                className="w-36 px-3 py-3 text-center text-xs font-medium tracking-wide text-muted-foreground uppercase"
                                            >
                                                {roleLabel(role.name)}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {grouped.map(
                                        ({ resource, permissions: perms }) => (
                                            <ResourceGroup
                                                key={resource}
                                                resource={resource}
                                                permissions={perms}
                                                roles={roles}
                                                draft={draft}
                                                canManage={can.manage}
                                                onToggle={toggle}
                                                onToggleAll={
                                                    toggleResourceForRole
                                                }
                                            />
                                        ),
                                    )}
                                </tbody>
                            </table>
                        </div>

                        {/* Mobile: stacked by role */}
                        <div className="divide-y divide-border/60 md:hidden">
                            {roles.map((role) => {
                                const selected = new Set(draft[role.id] ?? []);

                                return (
                                    <div key={role.id} className="p-4">
                                        <div className="mb-3 flex items-center gap-2">
                                            <h2 className="font-display text-base font-semibold">
                                                {roleLabel(role.name)}
                                            </h2>
                                            <Badge
                                                variant="outline"
                                                className="text-[10px]"
                                            >
                                                {selected.size}
                                            </Badge>
                                        </div>
                                        <div className="space-y-4">
                                            {grouped.map(
                                                ({
                                                    resource,
                                                    permissions: perms,
                                                }) => (
                                                    <div key={resource}>
                                                        <div className="mb-2 flex items-center justify-between">
                                                            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                                                {resourceLabel(
                                                                    resource,
                                                                )}
                                                            </p>
                                                            {can.manage && (
                                                                <button
                                                                    type="button"
                                                                    className="text-[11px] text-brand hover:underline"
                                                                    onClick={() => {
                                                                        const allOn =
                                                                            perms.every(
                                                                                (
                                                                                    p,
                                                                                ) =>
                                                                                    selected.has(
                                                                                        p,
                                                                                    ),
                                                                            );
                                                                        toggleResourceForRole(
                                                                            role.id,
                                                                            perms,
                                                                            !allOn,
                                                                        );
                                                                    }}
                                                                >
                                                                    {perms.every(
                                                                        (p) =>
                                                                            selected.has(
                                                                                p,
                                                                            ),
                                                                    )
                                                                        ? 'Limpar'
                                                                        : 'Todos'}
                                                                </button>
                                                            )}
                                                        </div>
                                                        <div className="space-y-2">
                                                            {perms.map(
                                                                (
                                                                    permission,
                                                                ) => {
                                                                    const id = `m-${role.id}-${permission}`;
                                                                    const checked =
                                                                        selected.has(
                                                                            permission,
                                                                        );

                                                                    return (
                                                                        <label
                                                                            key={
                                                                                permission
                                                                            }
                                                                            htmlFor={
                                                                                id
                                                                            }
                                                                            className="flex items-center gap-3 rounded-lg border border-border/50 px-3 py-2"
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
                                                                            <span className="text-sm">
                                                                                {permissionLabel(
                                                                                    permission,
                                                                                )}
                                                                            </span>
                                                                        </label>
                                                                    );
                                                                },
                                                            )}
                                                        </div>
                                                    </div>
                                                ),
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}
            </div>

            {isDirty && can.manage && (
                <div className="fixed inset-x-0 bottom-0 z-40 border-t border-border/70 bg-card/95 backdrop-blur supports-[backdrop-filter]:bg-card/80">
                    <div className="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-4 py-3 md:px-6">
                        <div>
                            <p className="text-sm font-medium">
                                Alterações por guardar
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {dirtyRoles.length} função
                                {dirtyRoles.length === 1 ? '' : 'ões'} com
                                alterações
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                disabled={saving}
                                onClick={discard}
                                className="gap-1.5"
                            >
                                <RotateCcw className="size-3.5" />
                                Descartar
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                disabled={saving}
                                onClick={saveAll}
                                className="gap-1.5"
                            >
                                {saving && (
                                    <Loader2 className="size-3.5 animate-spin" />
                                )}
                                Guardar
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}

function ResourceGroup({
    resource,
    permissions,
    roles,
    draft,
    canManage,
    onToggle,
    onToggleAll,
}: {
    resource: string;
    permissions: string[];
    roles: RoleRow[];
    draft: Record<number, string[]>;
    canManage: boolean;
    onToggle: (roleId: number, permission: string, checked: boolean) => void;
    onToggleAll: (
        roleId: number,
        permissions: string[],
        checked: boolean,
    ) => void;
}) {
    return (
        <>
            <tr className="border-b border-border/40 bg-muted/10">
                <td className="px-4 py-2.5 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {resourceLabel(resource)}
                </td>
                {roles.map((role) => {
                    const selected = new Set(draft[role.id] ?? []);
                    const allOn = permissions.every((p) => selected.has(p));
                    const someOn =
                        !allOn && permissions.some((p) => selected.has(p));

                    return (
                        <td key={role.id} className="px-3 py-2 text-center">
                            {canManage ? (
                                <Checkbox
                                    checked={
                                        allOn
                                            ? true
                                            : someOn
                                              ? 'indeterminate'
                                              : false
                                    }
                                    aria-label={`Todos em ${resourceLabel(resource)} para ${roleLabel(role.name)}`}
                                    onCheckedChange={(value) =>
                                        onToggleAll(
                                            role.id,
                                            permissions,
                                            value === true,
                                        )
                                    }
                                />
                            ) : (
                                <span className="text-xs text-muted-foreground">
                                    {allOn ? 'todos' : someOn ? 'parcial' : '—'}
                                </span>
                            )}
                        </td>
                    );
                })}
            </tr>
            {permissions.map((permission) => (
                <tr
                    key={permission}
                    className="border-b border-border/40 last:border-0 hover:bg-muted/20"
                >
                    <td className="px-4 py-2.5 pl-8">
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <span className="cursor-default text-sm">
                                    {permissionActionLabel(permission)}
                                </span>
                            </TooltipTrigger>
                            <TooltipContent
                                side="right"
                                className="font-mono text-xs"
                            >
                                {permission}
                            </TooltipContent>
                        </Tooltip>
                    </td>
                    {roles.map((role) => {
                        const selected = new Set(draft[role.id] ?? []);
                        const checked = selected.has(permission);
                        const id = `${role.id}-${permission}`;

                        return (
                            <td key={role.id} className="px-3 py-2 text-center">
                                <Checkbox
                                    id={id}
                                    checked={checked}
                                    disabled={!canManage}
                                    aria-label={`${permissionLabel(permission)} · ${roleLabel(role.name)}`}
                                    onCheckedChange={(value) =>
                                        onToggle(
                                            role.id,
                                            permission,
                                            value === true,
                                        )
                                    }
                                />
                            </td>
                        );
                    })}
                </tr>
            ))}
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
