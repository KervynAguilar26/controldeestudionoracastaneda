/*
 * nora_qr.js — capa común de escáneres QR del sistema Nora.
 */
(function (global) {
    'use strict';

    function esSeguro() {
        return global.isSecureContext === true ||
            ['localhost', '127.0.0.1', '::1'].indexOf(global.location.hostname) !== -1;
    }

    function hayMediaDevices() {
        return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    }

    function textoError(err, seguro) {
        if (!seguro) {
            return 'El navegador bloquea la cámara en conexiones HTTP. Abra el sistema por HTTPS ' +
                   '(o en localhost) y vuelva a intentar. Mientras tanto puede usar «Tomar foto del QR».';
        }
        var nombre = (err && err.name) ? err.name : '';
        var mensaje = String((err && err.message) || err || '');
        if (nombre === 'NotAllowedError' || nombre === 'PermissionDeniedError' || /permission|denied|denegad/i.test(mensaje)) {
            return 'Permiso de cámara denegado. Habilite el permiso en el navegador (ícono de candado) y vuelva a intentar.';
        }
        if (nombre === 'NotFoundError' || nombre === 'DevicesNotFoundError' || /no camera|not found/i.test(mensaje)) {
            return 'No se encontró ninguna cámara en el dispositivo.';
        }
        if (nombre === 'NotReadableError' || nombre === 'TrackStartError' || /could not start|in use/i.test(mensaje)) {
            return 'La cámara está ocupada por otra aplicación. Cierre otras apps de cámara e intente de nuevo.';
        }
        if (nombre === 'OverconstrainedError' || nombre === 'ConstraintNotSatisfiedError') {
            return 'La cámara trasera no aceptó esa configuración; pruebe con «Cambiar cámara» o «Tomar foto del QR».';
        }
        return 'No se pudo activar la cámara' + (mensaje ? ': ' + mensaje : '.');
    }

    function prepararVideo(contenedor) {
        var video = contenedor.querySelector('video');
        if (!video) { return; }
        video.setAttribute('playsinline', '');
        video.setAttribute('webkit-playsinline', '');
        video.setAttribute('autoplay', '');
        video.setAttribute('muted', '');
        video.muted = true;
        try {
            var promesa = video.play();
            if (promesa && typeof promesa.catch === 'function') { promesa.catch(function () {}); }
        } catch (e) { /* bloqueado hasta gesto del usuario */ }
    }

    function create(opciones) {
        opciones = opciones || {};
        var contenedor = document.getElementById(opciones.elementId);
        if (!contenedor) { throw new Error('NoraQR: no existe el elemento #' + opciones.elementId); }

        var scanner = null;
        var iniciando = false;
        var activo = false;
        var ultimoError = null;
        var camarasDetectadas = [];
        var indiceCamaraActual = -1;

        var barra = document.createElement('div');
        barra.className = 'nora-qr-toolbar mt-2 text-center';
        barra.innerHTML =
            '<div class="alert alert-info py-2 px-3 mb-2 small font-weight-bold nora-qr-status" role="status">' +
                '<i class="fas fa-info-circle mr-1"></i>Toque «Activar cámara» para iniciar el escáner.' +
            '</div>' +
            '<div class="d-flex flex-wrap justify-content-center">' +
                '<button type="button" class="btn btn-sm btn-warning font-weight-bold mx-1 mb-1 nora-qr-start">' +
                    '<i class="fas fa-camera mr-1"></i>Activar cámara' +
                '</button>' +
                '<button type="button" class="btn btn-sm btn-info font-weight-bold mx-1 mb-1 nora-qr-switch" style="display:none;">' +
                    '<i class="fas fa-sync-alt mr-1"></i>Cambiar cámara' +
                '</button>' +
                '<label class="btn btn-sm btn-secondary font-weight-bold mx-1 mb-1 nora-qr-file-label mb-0">' +
                    '<i class="fas fa-image mr-1"></i>Escanear foto / cámara del equipo' +
                    '<input type="file" accept="image/*" class="nora-qr-file d-none">' +
                '</label>' +
                '<label class="btn btn-sm btn-dark font-weight-bold mx-1 mb-1 nora-qr-capture-label mb-0">' +
                    '<i class="fas fa-camera-retro mr-1"></i>Tomar foto del QR' +
                    '<input type="file" accept="image/*" capture="environment" class="nora-qr-capture d-none">' +
                '</label>' +
                '<button type="button" class="btn btn-sm btn-danger font-weight-bold mx-1 mb-1 nora-qr-stop" style="display:none;">' +
                    '<i class="fas fa-stop mr-1"></i>Detener' +
                '</button>' +
            '</div>' +
            '<small class="d-block text-muted mt-1">Si abre la cámara frontal, pulse «Cambiar cámara» ' +
            'o use «Tomar foto del QR» para capturar con la cámara del equipo.</small>';
        contenedor.parentNode.insertBefore(barra, contenedor.nextSibling);

        var estado = barra.querySelector('.nora-qr-status');
        var botonStart = barra.querySelector('.nora-qr-start');
        var botonSwitch = barra.querySelector('.nora-qr-switch');
        var botonStop = barra.querySelector('.nora-qr-stop');
        var inputFile = barra.querySelector('.nora-qr-file');
        var inputCapture = barra.querySelector('.nora-qr-capture');

        function ponerEstado(mensaje, clase) {
            if (!estado) { return; }
            estado.className = 'alert alert-' + (clase || 'info') + ' py-2 px-3 mb-2 small font-weight-bold nora-qr-status';
            estado.innerHTML = mensaje;
        }

        function notificar(estadoActual, mensaje) {
            if (typeof opciones.onState === 'function') { opciones.onState(estadoActual, mensaje || ''); }
        }

        function actualizarBotones() {
            if (botonStart) { botonStart.disabled = iniciando || activo; }
            if (botonSwitch) { botonSwitch.style.display = (activo && camarasDetectadas.length > 1) ? '' : 'none'; }
            if (botonStop) { botonStop.style.display = activo ? '' : 'none'; }
        }

        function nuevoScanner() {
            try {
                if (scanner) { scanner.clear(); }
            } catch (e) { /* ya estaba limpio */ }
            contenedor.innerHTML = '';
            scanner = new Html5Qrcode(opciones.elementId, { verbose: false });
            return scanner;
        }

        function configuracion() {
            return {
                fps: opciones.fps || 10,
                qrbox: opciones.qrbox || { width: 220, height: 220 }
            };
        }

        function esTrasera(etiqueta) {
            return /back|rear|environment|traser|principal|external|camera 0|camera2 0/i.test(String(etiqueta || ''));
        }

        function esFrontal(etiqueta) {
            return /front|user|selfie|delanter|frontal/i.test(String(etiqueta || ''));
        }

        function ordenarCamaras(camaras) {
            var lista = (camaras || []).filter(function (c) { return c && c.id; }).slice();
            return lista.map(function (camara, indice) {
                var etiqueta = String(camara.label || '');
                var prioridad = esTrasera(etiqueta) ? 0 : (esFrontal(etiqueta) ? 2 : 1);
                return { camara: camara, indice: indice, prioridad: prioridad };
            }).sort(function (a, b) {
                return a.prioridad - b.prioridad || a.indice - b.indice;
            }).map(function (entrada) { return entrada.camara; });
        }

        function candidatosCamara() {
            // 1. Candidato estándar: forzar entorno (cámara trasera)
            var directos = [
                { facingMode: "environment" }
            ];

            if (!Html5Qrcode.getCameras) { 
                return Promise.resolve(directos.map(function(c) { return { constraint: c, camIndex: -1 }; })); 
            }

            return Html5Qrcode.getCameras().then(function (camaras) {
                camarasDetectadas = ordenarCamaras(camaras);
                var porDevice = [];
                var i;
                
                // 2. Buscar por ID de dispositivo, omitiendo las frontales
                for (i = 0; i < camarasDetectadas.length; i++) {
                    if (!esFrontal(camarasDetectadas[i].label)) {
                        porDevice.push({
                            constraint: camarasDetectadas[i].id, // html5-qrcode acepta el string del ID directo
                            camIndex: i
                        });
                    }
                }
                
                // 3. Combinar: Primero por ID específico, luego por facingMode
                var candidatos = porDevice.concat(directos.map(function (c) {
                    return { constraint: c, camIndex: -1 };
                }));
                
                return candidatos;
            }).catch(function () {
                return directos.map(function (c) { return { constraint: c, camIndex: -1 }; });
            });
        }

        function iniciarConConstraint(constraint, camIndex) {
            var sc = nuevoScanner();
            return sc.start(
                constraint, 
                configuracion(),
                function (texto) { if (typeof opciones.onDetect === 'function') { opciones.onDetect(texto); } },
                function () { /* errores de fotograma se ignoran */ }
            ).then(function () {
                activo = true;
                iniciando = false;
                if (camIndex >= 0) {
                    indiceCamaraActual = camIndex;
                } else if (camarasDetectadas.length > 0 && indiceCamaraActual < 0) {
                    indiceCamaraActual = 0;
                }
                prepararVideo(contenedor);
                actualizarBotones();
                var etiqueta = (indiceCamaraActual >= 0 && camarasDetectadas[indiceCamaraActual] && camarasDetectadas[indiceCamaraActual].label)
                    ? (' (' + camarasDetectadas[indiceCamaraActual].label + ')')
                    : '';
                ponerEstado('<i class="fas fa-check-circle mr-1"></i>Cámara activa' + etiqueta + '. Apunte al código QR.', 'success');
                notificar('started');
                return true;
            });
        }

        function intentar(indice, opcionesCamara) {
            if (indice >= opcionesCamara.length) {
                iniciando = false;
                actualizarBotones();
                var mensaje = 'No se pudo activar la cámara trasera automáticamente. ' +
                    'Use «Tomar foto del QR» para escanear con la cámara del equipo. ' +
                    textoError(ultimoError, esSeguro());
                ponerEstado('<i class="fas fa-exclamation-triangle mr-1"></i>' + mensaje, 'danger');
                notificar('error', mensaje);
                return Promise.resolve(false);
            }

            var item = opcionesCamara[indice];
            return iniciarConConstraint(item.constraint, item.camIndex).catch(function (err) {
                ultimoError = err;
                try { if (scanner && scanner.isScanning) { scanner.stop(); } } catch (e) { /* no inició */ }
                try { if (scanner) { scanner.clear(); } } catch (e) { /* no inició */ }
                scanner = null;
                return intentar(indice + 1, opcionesCamara);
            });
        }

        function cambiarCamara() {
            if (!activo || iniciando || camarasDetectadas.length < 2) { return Promise.resolve(false); }
            iniciando = true;
            actualizarBotones();
            var siguienteIndice = (indiceCamaraActual + 1) % camarasDetectadas.length;
            var camaraElegida = camarasDetectadas[siguienteIndice];
            
            // Pasar el ID de la cámara directamente
            var constraint = camaraElegida.id;

            var detenerActual = Promise.resolve();
            if (scanner && scanner.isScanning) {
                detenerActual = scanner.stop().catch(function () {});
            }

            return detenerActual.then(function () {
                return iniciarConConstraint(constraint, siguienteIndice);
            }).catch(function (err) {
                iniciando = false;
                actualizarBotones();
                ponerEstado('<i class="fas fa-exclamation-triangle mr-1"></i>No se pudo cambiar de cámara: ' +
                    textoError(err, esSeguro()), 'warning');
                return false;
            });
        }

        function start() {
            if (iniciando || activo) { return Promise.resolve(activo); }
            if (typeof Html5Qrcode === 'undefined') {
                ponerEstado('<i class="fas fa-exclamation-triangle mr-1"></i>No se pudo cargar la librería del escáner.', 'danger');
                return Promise.resolve(false);
            }
            if (!esSeguro()) {
                var msg = textoError(null, false);
                ponerEstado('<i class="fas fa-exclamation-triangle mr-1"></i>' + msg, 'warning');
                notificar('error', msg);
                return Promise.resolve(false);
            }
            if (!hayMediaDevices()) {
                var msg2 = 'Este navegador no permite acceso a la cámara. Use un navegador actualizado o «Tomar foto del QR».';
                ponerEstado('<i class="fas fa-exclamation-triangle mr-1"></i>' + msg2, 'warning');
                notificar('error', msg2);
                return Promise.resolve(false);
            }

            iniciando = true;
            activo = false;
            ultimoError = null;
            actualizarBotones();
            ponerEstado('<i class="fas fa-spinner fa-spin mr-1"></i>Solicitando acceso a la cámara...', 'info');
            notificar('starting');

            return candidatosCamara().then(function (lista) {
                return intentar(0, lista);
            }).catch(function (err) {
                ultimoError = err;
                iniciando = false;
                actualizarBotones();
                ponerEstado('<i class="fas fa-exclamation-triangle mr-1"></i>' + textoError(err, esSeguro()), 'danger');
                return false;
            });
        }

        function stop() {
            if (!activo && !scanner) {
                actualizarBotones();
                return Promise.resolve();
            }
            activo = false;
            iniciando = false;
            var promesa = Promise.resolve();
            if (scanner && scanner.isScanning) {
                promesa = scanner.stop().catch(function () {});
            }
            return promesa.then(function () {
                try { if (scanner) { scanner.clear(); } } catch (e) { /* ya estaba detenido */ }
                scanner = null;
                contenedor.innerHTML = '';
                actualizarBotones();
                ponerEstado('<i class="fas fa-info-circle mr-1"></i>Cámara detenida.', 'secondary');
                notificar('stopped');
            });
        }

        function escanearArchivo(archivo) {
            if (!archivo) { return; }
            if (typeof Html5Qrcode === 'undefined') {
                ponerEstado('<i class="fas fa-exclamation-triangle mr-1"></i>No se pudo cargar la librería del escáner.', 'danger');
                return;
            }
            var cadena = stop().then(function () {
                var sc = nuevoScanner();
                ponerEstado('<i class="fas fa-spinner fa-spin mr-1"></i>Leyendo el código QR de la imagen...', 'info');
                return sc.scanFile(archivo, true);
            });
            return cadena.then(function (texto) {
                ponerEstado('<i class="fas fa-check-circle mr-1"></i>QR leído desde la imagen.', 'success');
                notificar('file');
                if (typeof opciones.onDetect === 'function') { opciones.onDetect(texto); }
            }).catch(function (err) {
                ponerEstado('<i class="fas fa-exclamation-triangle mr-1"></i>No se pudo leer un QR en esa imagen. ' +
                    (err && err.message ? String(err.message) : ''), 'danger');
            });
        }

        if (botonStart) { botonStart.addEventListener('click', function () { start(); }); }
        if (botonSwitch) { botonSwitch.addEventListener('click', function () { cambiarCamara(); }); }
        if (botonStop) { botonStop.addEventListener('click', function () { stop(); }); }
        [inputFile, inputCapture].forEach(function (entrada) {
            if (!entrada) { return; }
            entrada.addEventListener('change', function () {
                escanearArchivo(entrada.files && entrada.files[0]);
                entrada.value = '';
            });
        });

        actualizarBotones();

        return {
            start: start,
            stop: stop,
            switchCamera: cambiarCamara,
            scanFile: escanearArchivo,
            isScanning: function () { return activo; },
            destroy: function () {
                stop();
                if (barra.parentNode) { barra.parentNode.removeChild(barra); }
            }
        };
    }

    global.NoraQR = {
        create: create,
        isSecure: esSeguro,
        isSupported: hayMediaDevices,
        describeError: textoError
    };
}(window));