<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="dark">
        <meta name="theme-color" content="#0A0A0A">

        <style>
            html {
                background-color: #0a0a0a;
                color-scheme: dark;
            }
        </style>

        {{-- Official R2CZ Auto mark (from r2cz-auto/src/app/icon.svg) --}}
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png" sizes="180x180">
        <link rel="icon" href="/favicon-192.png" type="image/png" sizes="192x192">

        <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
        <link
            href="https://fonts.bunny.net/css?family=inter:400,500,600,700|space-grotesk:400,500,600,700&display=swap"
            rel="stylesheet"
        />

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'R2CZ Auto Finder') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
