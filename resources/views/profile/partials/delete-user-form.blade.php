<section class="space-y-4">
    <header>
        <h3 class="text-base font-semibold text-rose-700">Eliminar cuenta</h3>
        <p class="mt-1 text-sm text-gray-600">
            Elimina tu usuario de <span class="font-medium">usuarios</span> de forma permanente.
            Descarga antes cualquier dato que quieras conservar.
        </p>
    </header>

    <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        <p class="font-semibold">Cuidado:</p>
        <p class="mt-1">
            No hay claves foráneas hacia <span class="font-medium">usuarios</span>, así que el borrado
            no se bloqueará, pero cajas, abonos, devoluciones e historiales guardan tu
            <span class="font-medium">usuario_id</span> y quedarán apuntando a un usuario que ya
            no existe. No dejes la tienda sin ningún administrador.
        </p>
    </div>

    <x-danger-button
        x-data=""
        x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')"
    >Eliminar cuenta</x-danger-button>

    <x-modal name="confirm-user-deletion" :show="$errors->userDeletion->isNotEmpty()" focusable>
        <form method="post" action="{{ route('profile.destroy') }}" class="p-6">
            @csrf
            @method('delete')

            <h3 class="text-lg font-medium text-gray-900">¿Seguro que quieres eliminar tu cuenta?</h3>

            <p class="mt-1 text-sm text-gray-600">
                Esta acción no se puede deshacer. Escribe tu contraseña para confirmar.
            </p>

            <div class="mt-5">
                <label for="password" class="block text-sm font-medium text-gray-700">Contraseña</label>
                <input id="password" name="password" type="password" autocomplete="current-password"
                       class="mt-1.5 block w-full min-h-[44px] rounded-xl border-gray-300 shadow-sm focus:border-rose-500 focus:ring-rose-500" />
                <x-input-error :messages="$errors->userDeletion->get('password')" class="mt-2" />
            </div>

            <div class="mt-6 flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
                <x-secondary-button type="button" x-on:click="$dispatch('close')" class="min-h-[44px]">
                    Cancelar
                </x-secondary-button>

                <x-danger-button class="min-h-[44px]">
                    Eliminar cuenta
                </x-danger-button>
            </div>
        </form>
    </x-modal>
</section>
