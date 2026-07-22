import type { ImgHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

type Props = ImgHTMLAttributes<HTMLImageElement> & {
    variant?: 'mark' | 'full';
};

/**
 * R2CZ Auto logo mark (favicon-style) or full wordmark.
 * Assets live in public/ (ported from r2cz-auto).
 */
export default function AppLogoIcon({
    className,
    variant = 'mark',
    alt = 'R2CZ Auto',
    ...props
}: Props) {
    if (variant === 'full') {
        return (
            <img
                src="/logo-light.svg"
                alt={alt}
                className={cn('h-8 w-auto', className)}
                {...props}
            />
        );
    }

    return (
        <img
            src="/favicon.svg"
            alt={alt}
            className={cn('size-8', className)}
            {...props}
        />
    );
}
