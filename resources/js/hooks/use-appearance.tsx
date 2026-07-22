import { useSyncExternalStore } from 'react';

export type ResolvedAppearance = 'dark';
export type Appearance = 'dark';

export type UseAppearanceReturn = {
    readonly appearance: Appearance;
    readonly resolvedAppearance: ResolvedAppearance;
    readonly updateAppearance: (mode: Appearance) => void;
};

const listeners = new Set<() => void>();

const setCookie = (name: string, value: string, days = 365): void => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;
    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

const applyDarkTheme = (): void => {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.classList.add('dark');
    document.documentElement.style.colorScheme = 'dark';
};

const subscribe = (callback: () => void) => {
    listeners.add(callback);

    return () => listeners.delete(callback);
};

/**
 * R2CZ Auto Finder is dark-only. Light/system modes are not offered.
 */
export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    localStorage.setItem('appearance', 'dark');
    setCookie('appearance', 'dark');
    applyDarkTheme();
}

export function useAppearance(): UseAppearanceReturn {
    const appearance: Appearance = useSyncExternalStore(
        subscribe,
        () => 'dark',
        () => 'dark',
    );

    const updateAppearance = (mode: Appearance): void => {
        localStorage.setItem('appearance', mode);
        setCookie('appearance', mode);
        applyDarkTheme();
        listeners.forEach((listener) => listener());
    };

    return {
        appearance,
        resolvedAppearance: 'dark',
        updateAppearance,
    } as const;
}
