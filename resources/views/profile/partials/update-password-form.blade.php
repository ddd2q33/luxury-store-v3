<section>
    <header>
        <h3 class="text-base font-semibold text-gray-900">Cambiar contraseña</h3>
        <p class="mt-1 text-sm text-gray-600">
            Usa una contraseña larga y aleatoria para mantener la cuenta segura.
        </p>
    </header>

    <form method="post" action="{{ route('password.update') }}" class="mt-5 space-y-5">
        @csrf
        @method('put')

        <div>
            <label for="current_password" class="block text-sm font-medium text-gray-700">Contraseña actual</label>
            <input id="current_password" name="current_password" type="password" autocomplete="current-password"
                   class="mt-1.5 block w-full min-h-[44px] rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
            <x-input-error :messages="$errors->updatePassword->get('current_password')" class="mt-2" />
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-gray-700">Nueva contraseña</label>
            <input id="password" name="password" type="password" autocomplete="new-password"
                   class="mt-1.5 block w-full min-h-[44px] rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
            <x-input-error :messages="$errors->updatePassword->get('password')" class="mt-2" />
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-medium text-gray-700">Confirmar contraseña</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password"
                   class="mt-1.5 block w-full min-h-[44px] rounded-xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
            <x-input-error :messages="$errors->updatePassword->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex flex-wrap items-center gap-4">
            <x-primary-button class="min-h-[44px]">Guardar</x-primary-button>

            @if (session('status') === 'password-updated')
                <p x-data="{ show: true }" x-show="show" x-cloak x-transition
                   x-init="setTimeout(() => show = false, 2000)"
                   class="text-sm font-medium text-emerald-600">Guardado.</p>
            @endif
        </div>
    </form>
</section>
