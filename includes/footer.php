</div> <!-- container-fluid -->
            </div> <!-- content -->

            <footer class="sticky-footer bg-white shadow-sm mt-4">
                <div class="container my-auto">
                    <div class="copyright text-center my-auto">
                        <span>Copyright &copy; Escuela Internacional Nora Castañeda <?= date('Y') ?></span>
                    </div>
                </div>
            </footer>
        </div> <!-- content-wrapper -->
    </div> <!-- wrapper -->

    <a class="scroll-to-top rounded" href="#page-top"><i class="fas fa-angle-up"></i></a>

    <!-- Scripts CDN -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/startbootstrap-sb-admin-2@4.1.4/js/sb-admin-2.min.js"></script>

    <script>
    // ══════════ RESPONSIVE (ronda 5): envuelve tablas huérfanas para scroll móvil ══════════
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('table').forEach(function (t) {
            if (!t.closest('.table-responsive')) {
                var d = document.createElement('div');
                d.className = 'table-responsive';
                t.parentNode.insertBefore(d, t);
                d.appendChild(t);
            }
        });
    });

    // ══════════ PWA (ronda 5): registrar el Service Worker de portales offline ══════════
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            var base = document.querySelector('meta[name="sw-base"]');
            var ruta  = (base ? base.content : '/') + 'sw.js';
            navigator.serviceWorker.register(ruta).catch(function () {});
        });
    }
    </script>
</body>
</html>