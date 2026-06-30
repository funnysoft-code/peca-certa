# JavaScript/TypeScript Conventions

## Conventions

- File naming: kebab-case for all files (`use-auth.ts`, `post-card.tsx`, `utils.ts`).
- Files with JSX use `.tsx`; pure TypeScript uses `.ts`.
- Interface and type names: PascalCase (`PostProps`, `AuthUser`, `SharedData`).
- Custom hooks: `use{FeatureName}` pattern (`useAuth`, `usePost`, `useForm`).
- Import alias: `@/` maps to `resources/js/` — e.g., `import { cn } from '@/lib/utils'`.
- Wayfinder imports: `@/actions/` for controller methods, `@/routes/` for named routes.
- No default exports EXCEPT page components and layout components.
- `app.tsx` is the Inertia entry point — root wrapped in `<StrictMode>` + `<TooltipProvider delayDuration={0}>`.
- Named exports for everything else (hooks, utilities, components, types).
- No `NODE_OPTIONS=--experimental-strip-types` — Bun handles TypeScript natively.

## Directory Structure

| Directory     | Purpose                                                    | Export Style              |
| ------------- | ---------------------------------------------------------- | ------------------------- |
| `pages/`      | Inertia page components                                    | `export default function` |
| `components/` | Reusable UI components                                     | Named exports             |
| `hooks/`      | Custom React hooks                                         | Named exports             |
| `types/`      | TypeScript definitions                                     | Named exports             |
| `lib/`        | Utility functions                                          | Named exports             |
| `layouts/`    | Layout wrappers                                            | `export default`          |
| `actions/`    | Wayfinder controller methods (auto-generated, do not edit) | —                         |
| `routes/`     | Wayfinder named routes (auto-generated, do not edit)       | —                         |

## Patterns

Inertia page component:
```tsx
import { Head } from '@inertiajs/react';

interface Props {
    posts: Post[];
    filters: { status?: string | null };
}

export default function PostIndex({ posts, filters }: Props) {
    return (
        <>
            <Head title="Posts" />
            {/* page content */}
        </>
    );
}

PostIndex.layout = { breadcrumbs: [{ title: 'Posts', href: '/posts' }] };
```

Wayfinder route usage:
```typescript
import { store as postsStore } from '@/actions/App/Http/Controllers/PostController';
import { index as postsIndex } from '@/routes/posts';

router.visit(postsIndex.url());
```

Inertia v3 `<Form>` with Wayfinder action URL:
```tsx
import { Form } from '@inertiajs/react';
import { store } from '@/actions/App/Http/Controllers/PostController';

<Form action={store.url()} method="post">
    {({ errors, processing }) => (
        <input name="title" />
    )}
</Form>
```

## Anti-Patterns

- Do not use relative imports when `@/` alias is available.
- Do not use `default export` for non-page, non-layout files.
- Do not manually edit files in `actions/` or `routes/` — run `php artisan wayfinder:generate` to regenerate.
- Do not create new root directories without approval.
