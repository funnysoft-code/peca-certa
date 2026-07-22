import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

/**
 * Appearance is dark-only. Component kept as a no-op shell for any residual imports.
 */
export default function AppearanceToggleTab({
    className = '',
    ...props
}: HTMLAttributes<HTMLDivElement>) {
    return (
        <div
            className={cn(
                'rounded-lg border border-border bg-card p-4 text-sm text-muted-foreground',
                className,
            )}
            {...props}
        >
            Tema escuro (único disponível)
        </div>
    );
}
