<?php

namespace App\Http\Livewire;

use DomainException;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Base de los modulos del panel. Centraliza la notificacion flotante y el
 * manejo de errores de dominio (p. ej. "stock insuficiente") para que todos los
 * modulos se comporten igual ante un error.
 */
abstract class PanelComponent extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'tailwind';

    /**
     * El endpoint de Livewire (`/livewire/message/{componente}`) es PUBLICO:
     * no pasa por el middleware `auth` de la ruta, solo por el grupo `web`.
     * Sin este chequeo, alguien con el snapshot JS de una sesion vieja podria
     * seguir disparando acciones (crear, borrar, cerrar caja) ya deslogueado o
     * desactivado. Con esto, toda accion de los 22 modulos exige sesion activa.
     */
    public function hydrate(): void
    {
        abort_unless(Auth::user()?->estaActivo(), 401, 'Sesion expirada.');
    }

    protected function toast(string $mensaje, string $tipo = 'ok'): void
    {
        $this->dispatchBrowserEvent('panel-toast', ['mensaje' => $mensaje, 'tipo' => $tipo]);
    }

    protected function toastOk(string $mensaje): void
    {
        $this->toast($mensaje);
    }

    protected function toastError(string $mensaje): void
    {
        $this->toast($mensaje, 'error');
    }

    /**
     * Ejecuta una accion del dominio mostrando el error al usuario en vez de
     * soltar una excepcion. Las transacciones se deshacen solas.
     *
     * Devuelve true si la accion se completo, false si fallo. Los llamadores
     * pueden usar el retorno para no cerrar un formulario y perder lo escrito
     * cuando hay un error de dominio.
     */
    protected function ejecutar(callable $accion, string $mensajeOk): bool
    {
        try {
            $accion();
            $this->toastOk($mensajeOk);

            return true;
        } catch (DomainException $e) {
            $this->toastError($e->getMessage());

            return false;
        }
    }
}
