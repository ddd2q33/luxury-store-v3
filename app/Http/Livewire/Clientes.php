<?php

namespace App\Http\Livewire;

use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Venta;
use Livewire\Component;
use Livewire\WithPagination;

class Clientes extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'tailwind';

    public string $search = '';

    // ========== Formulario ==========
    public bool $showForm = false;

    public ?int $editandoId = null;

    public string $nombre = '';

    public string $telefono = '';

    public string $correo = '';

    public string $direccion = '';

    public string $ciudad = '';

    public string $notas = '';

    // ========== Detalle / eliminar ==========
    public bool $showDetalle = false;

    public ?array $clienteDetalle = null;

    public ?int $porEliminar = null;

    protected $queryString = ['search' => ['except' => '']];

    protected function rules(): array
    {
        return [
            'nombre' => 'required|string|max:100',
            'telefono' => 'nullable|string|max:20',
            'correo' => 'nullable|email|max:100',
            'direccion' => 'nullable|string|max:150',
            'ciudad' => 'nullable|string|max:100',
            'notas' => 'nullable|string|max:1000',
        ];
    }

    protected $messages = [
        'nombre.required' => 'El nombre del cliente es requerido.',
        'correo.email' => 'El correo no tiene un formato válido.',
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $termino = trim($this->search);

        $clientes = Cliente::query()
            ->when($termino !== '', function ($q) use ($termino) {
                $q->where(function ($q) use ($termino) {
                    $q->where('nombre', 'like', "%{$termino}%")
                        ->orWhere('telefono', 'like', "%{$termino}%")
                        ->orWhere('correo', 'like', "%{$termino}%")
                        ->orWhere('direccion', 'like', "%{$termino}%")
                        ->orWhere('ciudad', 'like', "%{$termino}%");
                });
            })
            ->orderByDesc('id')
            ->paginate(8);

        // Gasto acumulado por cliente (de ventas completadas) para la tabla
        $gastosPorCliente = Venta::whereIn(\DB::raw('LOWER(estado)'), ['completada', 'pagada'])
            ->whereNotNull('cliente_id')
            ->selectRaw('cliente_id, COUNT(*) AS compras, COALESCE(SUM(total), 0) AS gastado')
            ->groupBy('cliente_id')
            ->pluck('gastado', 'cliente_id');
        $comprasPorCliente = Venta::whereIn(\DB::raw('LOWER(estado)'), ['completada', 'pagada'])
            ->whereNotNull('cliente_id')
            ->selectRaw('cliente_id, COUNT(*) AS compras')
            ->groupBy('cliente_id')
            ->pluck('compras', 'cliente_id');

        return view('livewire.clientes', [
            'clientes' => $clientes,
            'stats' => [
                'total' => Cliente::count(),
                'nuevosMes' => Cliente::whereMonth('creado_en', now()->month)->whereYear('creado_en', now()->year)->count(),
                'conTelefono' => Cliente::whereNotNull('telefono')->where('telefono', '!=', '')->count(),
                'conCorreo' => Cliente::whereNotNull('correo')->where('correo', '!=', '')->count(),
            ],
            'gastosPorCliente' => $gastosPorCliente,
            'comprasPorCliente' => $comprasPorCliente,
        ]);
    }

    // ====================================================================
    // CRUD
    // ====================================================================

    public function nuevo(): void
    {
        $this->resetForm();
        $this->editandoId = null;
        $this->showForm = true;
    }

    public function editar(int $id): void
    {
        $cliente = Cliente::findOrFail($id);
        $this->editandoId = $cliente->id;
        $this->nombre = (string) $cliente->nombre;
        $this->telefono = (string) $cliente->telefono;
        $this->correo = (string) $cliente->correo;
        $this->direccion = (string) $cliente->direccion;
        $this->ciudad = (string) $cliente->ciudad;
        $this->notas = (string) $cliente->notas;
        $this->showForm = true;
    }

    public function guardar(): void
    {
        $this->validate();

        $datos = [
            'nombre' => trim($this->nombre),
            'telefono' => trim($this->telefono),
            'correo' => trim($this->correo),
            'direccion' => trim($this->direccion),
            'ciudad' => trim($this->ciudad),
            'notas' => trim($this->notas),
        ];

        if ($this->editandoId) {
            Cliente::where('id', $this->editandoId)->update($datos);
            $this->toast('Cliente actualizado', 'ok');
        } else {
            Cliente::create($datos);
            $this->toast("Cliente \"{$datos['nombre']}\" creado", 'ok');
        }

        $this->resetForm();
        $this->showForm = false;
    }

    public function pedirEliminar(int $id): void
    {
        $this->porEliminar = $id;
    }

    public function eliminarConfirmado(): void
    {
        if ($this->porEliminar) {
            Cliente::where('id', $this->porEliminar)->delete();
            $this->toast('Cliente eliminado', 'ok');
        }
        $this->reset('porEliminar', 'showDetalle', 'clienteDetalle');
    }

    // ====================================================================
    // Detalle con historial
    // ====================================================================

    public function verDetalle(int $id): void
    {
        $cliente = Cliente::findOrFail($id);

        $ventas = Venta::where('cliente_id', $cliente->id)
            ->orderByDesc('fecha_venta')
            ->limit(10)
            ->get(['id', 'fecha_venta', 'total', 'metodo_pago', 'estado']);

        $pedidos = Pedido::where('cliente_id', $cliente->id)
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'numero_pedido', 'fecha_pedido', 'total', 'estado']);

        $this->clienteDetalle = [
            'id' => $cliente->id,
            'nombre' => $cliente->nombre,
            'telefono' => (string) $cliente->telefono,
            'correo' => (string) $cliente->correo,
            'direccion' => (string) $cliente->direccion,
            'ciudad' => (string) $cliente->ciudad,
            'notas' => (string) $cliente->notas,
            'creado' => $cliente->creado_en?->format('d/m/Y'),
            'ventas' => $ventas->map(fn ($v) => [
                'id' => $v->id,
                'fecha' => $v->fecha_venta?->format('d/m/Y H:i'),
                'total' => (float) $v->total,
                'metodo' => (string) $v->metodo_pago,
                'estado' => (string) $v->estado,
            ])->all(),
            'pedidos' => $pedidos->map(fn ($p) => [
                'id' => $p->id,
                'numero' => (string) $p->numero_pedido,
                'fecha' => $p->fecha_pedido?->format('d/m/Y'),
                'total' => (float) $p->total,
                'estado' => (string) $p->estado,
            ])->all(),
            'totalGastado' => (float) $ventas->whereIn('estado', ['Completada', 'completada', 'pagada', 'Pagada'])->sum('total'),
        ];
        $this->showDetalle = true;
    }

    public function closeDetalle(): void
    {
        $this->reset('showDetalle', 'clienteDetalle');
    }

    public function closeForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    private function resetForm(): void
    {
        $this->reset(['editandoId', 'nombre', 'telefono', 'correo', 'direccion', 'ciudad', 'notas']);
    }

    private function toast(string $mensaje, string $tipo = 'ok'): void
    {
        $this->dispatchBrowserEvent('clientes-toast', ['mensaje' => $mensaje, 'tipo' => $tipo]);
    }
}
