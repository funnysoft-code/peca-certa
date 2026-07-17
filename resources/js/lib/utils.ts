import type { InertiaLinkProps } from '@inertiajs/react';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(url: NonNullable<InertiaLinkProps['href']>): string {
    return typeof url === 'string' ? url : url.url;
}

const RELATIVE_TIME_UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 60 * 60 * 24 * 365],
    ['month', 60 * 60 * 24 * 30],
    ['week', 60 * 60 * 24 * 7],
    ['day', 60 * 60 * 24],
    ['hour', 60 * 60],
    ['minute', 60],
];

const relativeTimeFormatter = new Intl.RelativeTimeFormat('pt', {
    numeric: 'auto',
});

export function formatRelativeTime(isoDate: string): string {
    const seconds = (new Date(isoDate).getTime() - Date.now()) / 1000;

    for (const [unit, secondsInUnit] of RELATIVE_TIME_UNITS) {
        if (Math.abs(seconds) >= secondsInUnit) {
            return relativeTimeFormatter.format(
                Math.round(seconds / secondsInUnit),
                unit,
            );
        }
    }

    return relativeTimeFormatter.format(Math.round(seconds), 'second');
}
