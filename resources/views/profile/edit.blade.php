{{-- Perfil. Usa el layout del panel (no el de Breeze) para que el sidebar y el
     drawer movil sigan presentes: antes esta pagina salia con otro layout y se veia
     rota al entrar desde el menu. El titulo va aqui dentro porque el layout ya
     no tiene slot de header. --}}
<x-panel-layout>
    <div class="p-4 sm:p-6 lg:p-8">
        <h2 class="text-xl sm:text-2xl font-bold text-gray-900">Perfil</h2>
        <p class="mt-1 text-sm text-gray-600">
            Datos de tu cuenta, correo y contraseña. Solo tú puedes verlos.
        </p>

        <div class="mt-4 sm:mt-6 space-y-4 sm:space-y-6">
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5 sm:p-6 max-w-2xl">
                @include('profile.partials.update-profile-information-form')
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5 sm:p-6 max-w-2xl">
                @include('profile.partials.update-password-form')
            </div>

            <div class="bg-white rounded-2xl border border-rose-200 shadow-sm p-5 sm:p-6 max-w-2xl">
                @include('profile.partials.delete-user-form')
            </div>
        </div>
    </div>
</x-panel-layout>
