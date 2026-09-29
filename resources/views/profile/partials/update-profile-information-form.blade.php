<section>
    <header>
        <h3 class="text-base font-semibold text-gray-900">Información del usuario</h3>
        <p class="mt-1 text-sm text-gray-600">Actualiza tu nombre y tu correo de acceso.</p>
    </header>

    <form method="post" action="{{ route('profile.update') }}" class="mt-5 space-y-5">
        @csrf
        @method('patch')

        {{-- `usuarios` es la tabla legacy: no tiene `email_verified_at` y el proyecto
             no configura correo saliente, asi que no hay nada que verificar. Por eso
             el bloque de "correo sin verificar" de Breeze se elimino a proposito. --}}

        <div>
            <label for="nombre" class="block text-sm font-medium text-gray-700">Nombre</label>
            <input id="nombre" name="nombre" type="text" required autocomplete="name"
                   value="{{ old('nombre', $user->nombre) }}"
                   class="mt-1.5 block w-full min-h-[44px] rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
            <x-input-error class="mt-2" :messages="$errors->get('nombre')" />
        </div>

        <div>
            <label for="email" class="block text-sm font-medium text-gray-700">Correo electrónico</label>
            <input id="email" name="email" type="email" inputmode="email" required autocomplete="username"
                   value="{{ old('email', $user->email) }}"
                   class="mt-1.5 block w-full min-h-[44px] rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
            <x-input-error class="mt-2" :messages="$errors->get('email')" />
        </div>

        <div class="flex flex-wrap items-center gap-4">
            <x-primary-button class="min-h-[44px]">Guardar</x-primary-button>

            {{-- x-cloak: sin esto el "Guardado" parpadea en cada carga de pagina. --}}
            @if (session('status') === 'profile-updated')
                <p x-data="{ show: true }" x-show="show" x-cloak x-transition
                   x-init="setTimeout(() => show = false, 2000)"
                   class="text-sm font-medium text-emerald-600">Guardado.</p>
            @endif
        </div>
    </form>
</section>
