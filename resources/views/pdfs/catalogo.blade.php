{{-- Catálogo virtual en PDF. La capa de datos viene de CatalogoService; aquí
     solo se pinta. Las fotos llegan como data URI (Producto::imagenPdf) porque
     dompdf no puede leer el MEDIUMBLOB de la BD por su cuenta. Los productos
     sin imagen muestran un marcador de posición, nunca una foto rota. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Catálogo Luxury</title>
    <style>
        @page { margin: 22mm 14mm; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #1f2937;
            margin: 0;
        }
        h1 { font-size: 20px; margin: 0 0 2px; }
        .sub { color: #6b7280; font-size: 10px; }
        .cabecera { border-bottom: 2px solid #4f46e5; padding-bottom: 8px; margin-bottom: 14px; }
        .filtros { color: #4f46e5; font-weight: bold; font-size: 10px; margin-top: 4px; }
        .grid { width: 100%; }
        .celda { width: 25%; padding: 5px; vertical-align: top; }
        .tarjeta { border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden; }
        .foto { width: 100%; height: 96px; background: #f3f4f6; text-align: center; }
        .foto img { width: 100%; height: 96px; object-fit: cover; }
        .vacio { color: #d1d5db; font-size: 20px; line-height: 96px; }
        .cuerpo { padding: 6px 7px 8px; }
        .cat { color: #9ca3af; font-size: 8px; text-transform: uppercase; letter-spacing: .3px; }
        .nom { font-weight: bold; font-size: 10px; color: #111827; line-height: 1.25; height: 25px; overflow: hidden; }
        .precio { font-size: 12px; font-weight: bold; color: #111827; margin-top: 3px; }
        .precio.sin { color: #d97706; font-size: 9px; }
        .stock { font-size: 8px; color: #6b7280; margin-top: 1px; }
        .aviso { background: #fffbeb; border: 1px solid #fde68a; color: #92400e;
                 padding: 7px 9px; border-radius: 6px; font-size: 9px; margin-bottom: 12px; }
        .pie { margin-top: 12px; padding-top: 6px; border-top: 1px solid #e5e7eb;
               color: #9ca3af; font-size: 8px; }
    </style>
</head>
<body>

<div class="cabecera">
    <h1>Catálogo Luxury</h1>
    <p class="sub">Generado el {{ $generado->format('d/m/Y') }}</p>
    <p class="filtros">
        {{ $datos['total'] }} {{ $datos['total'] === 1 ? 'producto' : 'productos' }}
        @if ($datos['filtros']['soloDisponibles'])
            · solo disponibles
        @else
            · incluye agotados
        @endif
        @if ($datos['filtros']['soloConImagen'])
            · solo con imagen
        @endif
        @if ($datos['categoriasIncluidas'])
            · {{ $datos['categoriasIncluidas'] }}
        @endif
    </p>
</div>

@if ($datos['sinPrecio'] > 0)
    <div class="aviso">
        <strong>Nota:</strong> {{ $datos['sinPrecio'] }} {{ $datos['sinPrecio'] === 1 ? 'producto aparece' : 'productos aparecen' }}
        como &laquo;Sin precio&raquo; porque aún no tienen valor cargado. Consulta el precio en tienda.
    </div>
@endif

<table class="grid">
    @foreach ($datos['productos']->chunk(4) as $fila)
        <tr>
            @foreach ($fila as $producto)
                <td class="celda">
                    <div class="tarjeta">
                        <div class="foto">
                            @if ($imagen = $producto->imagenPdf())
                                <img src="{{ $imagen }}" alt="{{ $producto->nombre }}">
                            @else
                                <div class="vacio">&#9634;</div>
                            @endif
                        </div>
                        <div class="cuerpo">
                            <div class="cat">{{ $producto->categoria?->nombre ?? 'Sin categoría' }}</div>
                            <div class="nom">{{ $producto->nombre }}</div>
                            @if ($producto->precio)
                                <div class="precio">{{ \App\Services\CatalogoService::precioLegible($producto->precio) }}</div>
                            @else
                                <div class="precio sin">Sin precio</div>
                            @endif
                            <div class="stock">
                                @if ($producto->stock > 0)
                                    Disponibles: {{ $producto->stock }}
                                @else
                                    Agotado
                                @endif
                            </div>
                        </div>
                    </div>
                </td>
            @endforeach
            {{-- Rellena la última fila para que el borde no se descentre --}}
            @for ($i = count($fila); $i < 4; $i++)
                <td class="celda"></td>
            @endfor
        </tr>
    @endforeach
</table>

<p class="pie">
    Luxury · Catálogo de productos. Los precios y la disponibilidad pueden cambiar;
    este documento es informativo.
</p>

</body>
</html>
