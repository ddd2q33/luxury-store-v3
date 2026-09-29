@php
    // La raiz no tiene pagina propia: es un back-office. Quien entra sin
    // sesion va al login; con sesion, directo al panel.
    // (Antes esto servia la landing por defecto de Laravel.)
endphp
<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta http-equiv="refresh" content="0;url={{ Auth::check() ? route('dashboard') : route('login') }}">
        <title>{{ config('app.name', 'Luxury Premium Store') }}</title>
    </head>
    <body>
        @if (Auth::check())
            <a href="{{ route('dashboard') }}">Ir al panel…</a>
        @else
            <a href="{{ route('login') }}">Iniciar sesión…</a>
        @endif
    </body>
</html>
