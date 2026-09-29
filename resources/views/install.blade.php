<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Installatie · Bora Foto</title>
    @vite(['resources/css/app.css'])
</head>
<body>
<main class="mx-auto max-w-lg px-4 py-10">
    <h1 class="text-2xl font-semibold">Bora Foto installeren</h1>

    @if ($done)
        <div class="mt-6 rounded-2xl bg-white p-6 ring-1 ring-stone-200">
            <p class="font-medium text-emerald-700">De installatie is gelukt.</p>
            <p class="mt-2 text-sm text-stone-600">Log in met het e-mailadres en wachtwoord dat je net hebt gekozen. Kijk daarna bij Overzicht &gt; Systeemcontrole wat nog aandacht nodig heeft.</p>
            <a href="{{ $done }}" class="mt-5 flex h-12 items-center justify-center rounded-xl bg-brand-600 font-medium text-white">Naar inloggen</a>
        </div>
    @else
        <p class="mt-2 text-sm text-stone-600">Eenmalig: koppel de database en maak het beheerdersaccount aan. Maak de database eerst aan in Plesk &gt; Databases &gt; Database toevoegen, en noteer de databasenaam, gebruikersnaam en het wachtwoord.</p>

        @if ($errors)
            <div class="mt-6 rounded-xl bg-red-50 p-4 text-sm text-red-800 ring-1 ring-red-200" role="alert">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($errors as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="/install" class="mt-6 space-y-6">
            <fieldset class="space-y-4 rounded-2xl bg-white p-5 ring-1 ring-stone-200">
                <legend class="px-1 font-semibold">1. Database</legend>
                <label class="block text-sm font-medium">Databasenaam
                    <input name="db_database" required value="{{ $input['db_database'] ?? '' }}" autocomplete="off" class="mt-1 block h-11 w-full rounded-xl border border-stone-200 px-3.5 text-base">
                </label>
                <label class="block text-sm font-medium">Databasegebruiker
                    <input name="db_username" required value="{{ $input['db_username'] ?? '' }}" autocomplete="off" class="mt-1 block h-11 w-full rounded-xl border border-stone-200 px-3.5 text-base">
                </label>
                <label class="block text-sm font-medium">Wachtwoord van de database
                    <input name="db_password" type="password" autocomplete="off" class="mt-1 block h-11 w-full rounded-xl border border-stone-200 px-3.5 text-base">
                </label>
            </fieldset>

            <fieldset class="space-y-4 rounded-2xl bg-white p-5 ring-1 ring-stone-200">
                <legend class="px-1 font-semibold">2. Beheerdersaccount (Super Admin)</legend>
                <label class="block text-sm font-medium">Naam
                    <input name="name" required value="{{ $input['name'] ?? '' }}" autocomplete="name" class="mt-1 block h-11 w-full rounded-xl border border-stone-200 px-3.5 text-base">
                </label>
                <label class="block text-sm font-medium">E-mailadres
                    <input name="email" type="email" required value="{{ $input['email'] ?? '' }}" autocomplete="email" class="mt-1 block h-11 w-full rounded-xl border border-stone-200 px-3.5 text-base">
                </label>
                <label class="block text-sm font-medium">Wachtwoord
                    <input name="password" type="password" required minlength="10" autocomplete="new-password" class="mt-1 block h-11 w-full rounded-xl border border-stone-200 px-3.5 text-base">
                    <span class="mt-1 block text-xs font-normal text-stone-500">Minimaal 10 tekens, met letters en cijfers.</span>
                </label>
                <label class="block text-sm font-medium">Herhaal wachtwoord
                    <input name="password_confirmation" type="password" required autocomplete="new-password" class="mt-1 block h-11 w-full rounded-xl border border-stone-200 px-3.5 text-base">
                </label>
            </fieldset>

            <fieldset class="space-y-4 rounded-2xl bg-white p-5 ring-1 ring-stone-200">
                <legend class="px-1 font-semibold">3. OpenAI (optioneel)</legend>
                <label class="block text-sm font-medium">OpenAI API-key
                    <input name="openai_key" type="password" autocomplete="off" placeholder="sk-..." class="mt-1 block h-11 w-full rounded-xl border border-stone-200 px-3.5 text-base">
                    <span class="mt-1 block text-xs font-normal text-stone-500">Zonder key krijgen foto's alleen lokale basiscorrecties. Kan later in het .env-bestand.</span>
                </label>
            </fieldset>

            <button type="submit" class="flex h-12 w-full items-center justify-center rounded-xl bg-brand-600 font-medium text-white">Installeren</button>
            <p class="text-center text-xs text-stone-500">Dit kan tot een minuut duren.</p>
        </form>
    @endif
</main>
</body>
</html>
