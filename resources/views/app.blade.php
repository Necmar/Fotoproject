<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fafaf9">
    <meta name="robots" content="noindex">
    <title>{{ config('app.name') }}</title>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/main.jsx'])
</head>
<body>
    <div id="app" data-locale="{{ app()->getLocale() }}"></div>
    <noscript>Deze applicatie heeft JavaScript nodig. / This application requires JavaScript.</noscript>
</body>
</html>
