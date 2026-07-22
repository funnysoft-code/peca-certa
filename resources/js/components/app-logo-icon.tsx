import type { ImgHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

type Props = ImgHTMLAttributes<HTMLImageElement> & {
    /** `full` = wordmark (logo-light), `mark` = square app icon from r2cz-auto */
    variant?: 'mark' | 'full';
};

/**
 * Official R2CZ Auto brand assets (from ~/Code/r2cz-auto):
 * - full → public/logo-light.svg (wordmark for dark backgrounds)
 * - mark → public/logo-icon.svg (app icon / favicon mark)
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
            src="/logo-icon.svg"
            alt={alt}
            className={cn('size-8 rounded-md', className)}
            {...props}
        />
    );
}
