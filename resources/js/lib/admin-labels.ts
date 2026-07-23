/**
 * Human labels for admin UI. Permission keys stay SSOT in the backend;
 * this map is presentation only.
 */

export const roleLabels: Record<string, string> = {
    admin: 'Administrador',
    user: 'Operador',
};

export const resourceLabels: Record<string, string> = {
    admin: 'Painel admin',
    users: 'Utilizadores',
    roles: 'Funções',
    permissions: 'Permissões',
    parts: 'Peças',
    identify: 'Identificar',
    'search-runs': 'Pesquisas',
    findings: 'Resultados',
    analytics: 'Análises',
};

export const permissionLabels: Record<string, string> = {
    'admin.access': 'Acesso ao painel',
    'users.view': 'Ver utilizadores',
    'users.manage': 'Gerir e convidar',
    'roles.view': 'Ver funções',
    'roles.manage': 'Gerir funções',
    'permissions.view': 'Ver permissões',
    'permissions.manage': 'Gerir permissões',
    'parts.view': 'Ver',
    'parts.create': 'Criar',
    'parts.manage': 'Gerir todos',
    'identify.view': 'Ver',
    'identify.create': 'Criar',
    'identify.manage': 'Gerir todos',
    'search-runs.view': 'Ver',
    'search-runs.manage': 'Gerir todos',
    'findings.view': 'Ver',
    'findings.manage': 'Gerir todos',
    'analytics.view': 'Ver',
    'analytics.manage': 'Gerir',
};

export function roleLabel(role: string): string {
    return roleLabels[role] ?? role;
}

export function resourceLabel(resource: string): string {
    return resourceLabels[resource] ?? resource;
}

export function permissionLabel(permission: string): string {
    return permissionLabels[permission] ?? permission;
}

export function permissionActionLabel(permission: string): string {
    const [, action] = permission.split('.');

    if (permissionLabels[permission]) {
        // Prefer short action fragment when grouped under a resource.
        const label = permissionLabels[permission];
        if (action === 'view' || action === 'create' || action === 'manage') {
            return label;
        }

        return label;
    }

    return action ?? permission;
}

export function initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean).slice(0, 2);

    if (parts.length === 0) {
        return '?';
    }

    return parts.map((part) => part[0]?.toUpperCase() ?? '').join('');
}
