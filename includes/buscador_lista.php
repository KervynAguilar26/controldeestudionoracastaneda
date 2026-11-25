<?php
// /sistema_nora/includes/buscador_lista.php
// Buscador reutilizable para listados: filtra por nombre, apellido o cédula
// directamente en el navegador, sin recargar ni alterar los formularios de acción.
// Las acciones siguen enviando siempre su id real (id_participante, id_nino, etc.).

if (!function_exists('buscador_lista_render')) {
    /**
     * @param string $placeholder Texto del campo.
     * @param string $tablas      Selector CSS de las tablas a filtrar (por defecto, todas).
     */
    function buscador_lista_render(string $placeholder = 'Buscar por nombre o cédula…', string $tablas = 'table'): void
    {
        static $secuencia = 0;
        $secuencia++;
        $id     = 'buscador_lista_' . $secuencia;
        $idLimp = $id . '_limpiar';
        $idInfo = $id . '_info';
        ?>
        <div class="buscador-lista mb-3">
            <div class="input-group" style="max-width: 460px;">
                <div class="input-group-prepend">
                    <span class="input-group-text bg-white"><i class="fas fa-search text-warning"></i></span>
                </div>
                <input type="text" id="<?= $id ?>" class="form-control font-weight-bold"
                       placeholder="<?= htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') ?>"
                       autocomplete="off" aria-label="Buscar por nombre o cédula">
                <div class="input-group-append">
                    <button type="button" class="btn btn-outline-secondary font-weight-bold" id="<?= $idLimp ?>">Limpiar</button>
                </div>
            </div>
            <small class="text-muted d-block mt-1" id="<?= $idInfo ?>">
                Puede escribir el nombre, el apellido o la cédula (también funciona V-12345678).
            </small>
        </div>
        <script>
        (function () {
            var input = document.getElementById('<?= $id ?>');
            var limpiar = document.getElementById('<?= $idLimp ?>');
            var info = document.getElementById('<?= $idInfo ?>');
            var normalizar = function (valor) {
                return (valor || '').toString()
                    .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                    .replace(/[^0-9a-zA-Z ]/g, ' ')
                    .replace(/\s+/g, ' ')
                    .toLowerCase().trim();
            };
            function filtrar() {
                var q = normalizar(input.value);
                var visibles = 0;
                var total = 0;
                document.querySelectorAll('<?= $tablas ?> tbody tr').forEach(function (fila) {
                    if (!fila.querySelector('td')) { return; }
                    total++;
                    var coincide = (q === '' || normalizar(fila.textContent).indexOf(q) !== -1);
                    var ocultoPorOtroFiltro = fila.classList.contains('filtro-oculto');
                    fila.style.display = (coincide && !ocultoPorOtroFiltro) ? '' : 'none';
                    if (coincide) { visibles++; }
                });
                if (info) {
                    info.textContent = q === ''
                        ? 'Puede escribir el nombre, el apellido o la cédula (también funciona V-12345678).'
                        : 'Mostrando ' + visibles + ' de ' + total + ' registros.';
                }
            }
            input.addEventListener('keyup', filtrar);
            input.addEventListener('input', filtrar);
            limpiar.addEventListener('click', function () { input.value = ''; filtrar(); input.focus(); });
        })();
        </script>
        <?php
    }
}
