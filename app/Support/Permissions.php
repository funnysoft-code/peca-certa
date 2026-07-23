<?php

declare(strict_types=1);

namespace App\Support;

/**
 * SSOT permission inventory for seeders, Inertia auth.can, and admin matrix.
 */
final class Permissions
{
    public const string AdminAccess = 'admin.access';

    public const string UsersView = 'users.view';

    public const string UsersManage = 'users.manage';

    public const string RolesView = 'roles.view';

    public const string RolesManage = 'roles.manage';

    public const string PermissionsView = 'permissions.view';

    public const string PermissionsManage = 'permissions.manage';

    public const string PartsView = 'parts.view';

    public const string PartsCreate = 'parts.create';

    public const string PartsManage = 'parts.manage';

    public const string IdentifyView = 'identify.view';

    public const string IdentifyCreate = 'identify.create';

    public const string IdentifyManage = 'identify.manage';

    public const string SearchRunsView = 'search-runs.view';

    public const string SearchRunsManage = 'search-runs.manage';

    public const string FindingsView = 'findings.view';

    public const string FindingsManage = 'findings.manage';

    public const string AnalyticsView = 'analytics.view';

    public const string AnalyticsManage = 'analytics.manage';

    public const string RoleAdmin = 'admin';

    public const string RoleUser = 'user';

    /**
     * Full permission set (platform + domain).
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::AdminAccess,
            self::UsersView,
            self::UsersManage,
            self::RolesView,
            self::RolesManage,
            self::PermissionsView,
            self::PermissionsManage,
            self::PartsView,
            self::PartsCreate,
            self::PartsManage,
            self::IdentifyView,
            self::IdentifyCreate,
            self::IdentifyManage,
            self::SearchRunsView,
            self::SearchRunsManage,
            self::FindingsView,
            self::FindingsManage,
            self::AnalyticsView,
            self::AnalyticsManage,
        ];
    }

    /**
     * Permissions granted to the default operator `user` role.
     *
     * @return list<string>
     */
    public static function forUserRole(): array
    {
        return [
            self::PartsView,
            self::PartsCreate,
            self::IdentifyView,
            self::IdentifyCreate,
            self::SearchRunsView,
            self::FindingsView,
            self::AnalyticsView,
        ];
    }
}
