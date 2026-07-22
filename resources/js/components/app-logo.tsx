import AppLogoIcon from '@/components/app-logo-icon';

/**
 * Sidebar / header brand lockup: official R2CZ Auto mark + product name.
 */
export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 shrink-0 items-center justify-center overflow-hidden rounded-md">
                <AppLogoIcon className="size-8" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="truncate font-display text-sm leading-tight font-semibold tracking-tight">
                    R2CZ Auto Finder
                </span>
            </div>
        </>
    );
}
