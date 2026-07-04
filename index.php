<?php
/**
 * Página principal formulario de subida y listado de archivos.
 *
 * PRINCIPIOS POO APLICADOS EN ESTE ARCHIVO:
 *   - Instanciación de objeto: new GestorArchivos() crea una instancia
 *     que encapsula el directorio y la lógica de validación.
 *   - Llamada a métodos públicos: $gestor->listar() y GestorArchivos::obtenerAlias()
 *     acceden a la funcionalidad sin exponer la implementación interna.
 *   - Encapsulamiento: este archivo nunca toca directamente el sistema de archivos;
 *     toda esa responsabilidad está dentro de la clase.
 *
 * SEGURIDAD:
 *   - Cookie de sesión con HttpOnly (inaccessible desde JS) y SameSite=Strict
 *   - Token CSRF generado con random_bytes para todos los formularios
 *   - Todas las salidas al HTML pasan por h() que aplica htmlspecialchars (anti-XSS)
 *   - Cabeceras HTTP de seguridad: X-Frame-Options, CSP, nosniff
 */

// Configurar la cookie de sesión ANTES de session_start
// HttpOnly impide que JavaScript lea la cookie (protege contra XSS que robe la sesión)
// SameSite=Strict impide que navegadores envíen la cookie en peticiones originadas en otros sitios
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => false,     // Cambiar a true en producción con HTTPS (Actualmente no se usa)
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

// Cabeceras de seguridad HTTP enviadas en cada respuesta
header('X-Frame-Options: DENY');                      // Impide que la página se cargue en un iframe (anti-clickjacking)
header('X-Content-Type-Options: nosniff');          // Impide que el navegador reinterprete el tipo MIME
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data:; script-src 'self' 'unsafe-inline';");

// Generar token CSRF si no existe en la sesión actual
// random_bytes(32) produce 32 bytes impredecibles; bin2hex los convierte en 64 caracteres hex
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// INSTANCIACIÓN DEL OBJETO (principio de instanciación POO)
// Se crea un objeto de la clase GestorArchivos pasando el directorio como parámetro.
// El constructor inicializa el estado interno ($directorio, $tamañoMaximo)
// y crea el .htaccess de protección si no existe.
require_once __DIR__ . '/GestorArchivos.php';
$gestor = new GestorArchivos(__DIR__ . '/uploads/');

// Llamada al MÉTODO PÚBLICO listar() - el objeto devuelve los archivos
// sin que este archivo sepa nada sobre cómo se recorre el directorio internamente
$archivos = $gestor->listar();

// Mensajes flash del patrón PRG (Post/Redirect/Get)
$mensajeOk  = $_SESSION['msg_ok']  ?? null;
$mensajeErr = $_SESSION['msg_err'] ?? null;
unset($_SESSION['msg_ok'], $_SESSION['msg_err']);

// h() aplica htmlspecialchars con ENT_QUOTES para prevenir XSS
// Debe usarse en TODA variable que se imprima en el HTML
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function badgeClase(string $nombre): string {
    return in_array(strtolower(pathinfo($nombre, PATHINFO_EXTENSION)), ['jpg','jpeg','png'])
        ? 'badge-img' : 'badge-pdf';
}
function badgeTxt(string $nombre): string {
    return strtoupper(pathinfo($nombre, PATHINFO_EXTENSION));
}
// MÉTODO ESTÁTICO: obtenerAlias() se llama sin instanciar un nuevo objeto
// porque es una utilidad que solo consulta datos de sesión
function alias(string $nombre): string {
    return h(GestorArchivos::obtenerAlias($nombre));
}

// Calcular el tamaño total de todos los archivos para el hero
$totalBytes = array_sum(array_column($archivos, 'bytes'));
function formatBytes(int $b): string {
    if ($b >= 1048576) return round($b/1048576, 1) . ' MB';
    if ($b >= 1024)    return round($b/1024)       . ' KB';
    return $b . ' B';
}
?>
<!DOCTYPE html>
<html lang="es" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestor de Archivos Seguro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,600;1,9..144,300&family=Space+Mono:ital,wght@0,400;0,700;1,400&family=Plus+Jakarta+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>✦</text></svg>" />
</head>
<body>

<div class="pixel-bg" aria-hidden="true"></div>

<!-- ═══════════════════ HEADER ═══════════════════ -->
<header class="site-header">
    <div class="container">
        <nav class="nav-inner" aria-label="Navegación principal">
            <a href="index.php" class="nav-brand" aria-label="Inicio">
                <img src="img/MegClaro.png" alt="Studio" class="logo-light" height="32">
                <img src="img/MegOscuro.png" alt="Studio" class="logo-dark"  height="32">
            </a>
            <div class="nav-links">
                <a href="#subir"   class="nav-link active"><i class="fa-solid fa-upload"></i><span>Subir</span></a>
                <a href="#listado" class="nav-link"><i class="fa-solid fa-folder-open"></i><span>Archivos</span></a>
                <a href="seguridad.php" class="nav-link"><i class="fa-solid fa-shield-halved"></i><span>Seguridad</span></a>
            </div>
            <button class="theme-toggle" id="themeToggle" aria-label="Cambiar tema">
                <span class="theme-icon-light">☀</span>
                <span class="theme-icon-dark">◑</span>
            </button>
        </nav>
    </div>
</header>

<!-- ═══════════════════ MAIN ═══════════════════ -->
<main>

    <!-- HERO -->
    <section class="hero" aria-labelledby="hero-title">
        <div class="container">
            <div class="hero-eyebrow">módulo de archivos seguro</div>
            <h1 id="hero-title">Gestiona tus <em>archivos</em><br>de forma segura</h1>
            <p class="hero-sub">
                Sube, visualiza y elimina archivos PDF, JPG y PNG
                con validaciones de seguridad en cada paso.
            </p>
            <!-- Solo archivos subidos y tamaño total -->
            <div class="hero-stat-row">
                <div class="hero-stat">
                    <div class="hero-stat-num"><?= count($archivos) ?></div>
                    <div class="hero-stat-label">archivos</div>
                </div>
                <div class="hero-stat">
                    <div class="hero-stat-num">3</div>
                    <div class="hero-stat-label">MB máx.</div>
                </div>
            </div>
        </div>
    </section>

    <div class="container page-sections">

        <!-- Alertas flash -->
        <?php if ($mensajeOk): ?>
        <div class="alert alert-ok" role="alert">
            <i class="fa-solid fa-circle-check"></i> <?= h($mensajeOk) ?>
        </div>
        <?php endif; ?>
        <?php if ($mensajeErr): ?>
        <div class="alert alert-err" role="alert">
            <i class="fa-solid fa-circle-xmark"></i> <?= h($mensajeErr) ?>
        </div>
        <?php endif; ?>
 
        <!-- ── SUBIR ──────────────────────────────────────────── -->
        <section class="card" id="subir" aria-labelledby="h-subir">
            <div class="card-header">
                <span class="card-header-icon"><i class="fa-solid fa-cloud-arrow-up"></i></span>
                <h2 id="h-subir">Subir archivo</h2>
            </div>
            <div class="card-body">
                <!--
                    enctype="multipart/form-data" es obligatorio para que PHP reciba el archivo.
                    El campo csrf_token oculto implementa la protección CSRF:
                    si un sitio externo intenta enviar este formulario, no tendrá el token
                    correcto de la sesión y la solicitud será rechazada.
                -->
                <form method="POST" action="subir.php" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <div class="form-group">
                        <label for="archivo">Seleccionar un archivo</label>
                        <input type="file" id="archivo" name="archivo"
                            accept=".pdf,.jpg,.jpeg,.png" required>
                        <p class="form-hint">
                            Tipos permitidos: <strong>PDF, JPG, PNG</strong>
                            &nbsp;·&nbsp; Tamaño máximo: <strong>3 MB</strong>
                        </p>
                        </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-upload"></i> Subir archivo
                    </button>
                </form>
            </div>
        </section>

        <!-- ── LISTADO ────────────────────────────────────────── -->
        <section class="card" id="listado" aria-labelledby="h-listado">
            <div class="card-header">
                <span class="card-header-icon"><i class="fa-solid fa-folder-open"></i></span>
                <h2 id="h-listado">
                    Archivos subidos
                    <span class="count-chip"><?= count($archivos) ?></span>
                </h2>
            </div>

            <?php if (empty($archivos)): ?>
            <div class="empty">
                <i class="fa-solid fa-inbox"></i>
                <h3>Sin archivos todavía</h3>
                <p>Usa el formulario de arriba para subir tu primer documento ¡Adelante!</p>
            </div>
            <?php else: ?>
            <div class="table-wrap">
                <table class="file-table" aria-label="Listado de archivos">
                    <thead>
                        <tr>
                            <th scope="col">Archivo</th>
                            <th scope="col">Tamaño</th>
                            <th scope="col">Fecha</th>
                            <th scope="col">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($archivos as $f): ?>
                        <tr>
                            <td class="td-nombre">
                                <div class="file-name-cell">
                                    <span class="badge <?= badgeClase($f['nombre']) ?>"
                                          aria-label="Tipo de archivo">
                                        <?= badgeTxt($f['nombre']) ?>
                                    </span>
                                    <div class="file-alias-wrap"
                                         title="<?= alias($f['nombre']) ?>">
                                        <div class="file-alias"><?= alias($f['nombre']) ?></div>
                                        <div class="file-real" title="<?= h($f['nombre']) ?>">
                                            <?= h($f['nombre']) ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td><?= h($f['tamaño']) ?></td>
                            <td class="file-date"><?= h($f['fecha']) ?></td>
                            <td>
                                <div class="file-actions">
                                    <!-- Descarga segura vía descargar.php -->
                                    <a href="descargar.php?archivo=<?= urlencode($f['nombre']) ?>&csrf_token=<?= urlencode($csrf) ?>"
                                       class="btn btn-outline btn-xs"
                                       title="Descargar archivo">
                                        <i class="fa-solid fa-download"></i>
                                        Descargar
                                    </a>

                                    <!-- Renombrar: abre modal con alias actual -->
                                    <button type="button"
                                            class="btn btn-ghost btn-xs"
                                            onclick="abrirRenombrar(
                                                '<?= h(addslashes($f['nombre'])) ?>',
                                                '<?= h(addslashes(GestorArchivos::obtenerAlias($f['nombre']))) ?>'
                                            )"
                                            title="Renombrar archivo">
                                        <i class="fa-solid fa-pen"></i>
                                        Renombrar
                                    </button>

                                    <!-- Eliminar: abre modal de confirmación -->
                                    <button type="button"
                                            class="btn btn-danger btn-xs"
                                            onclick="abrirConfirmar(
                                                '<?= h(addslashes($f['nombre'])) ?>',
                                                '<?= h(addslashes(GestorArchivos::obtenerAlias($f['nombre']))) ?>'
                                            )"
                                            title="Eliminar archivo">
                                        <i class="fa-solid fa-trash-can"></i>
                                        Eliminar
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
</div><!-- /container -->

</main>
<!-- ═══════════════════ FOOTER ═══════════════════ -->
<footer class="site-footer">
    <div class="container">
        <div class="footer-inner">
            <div class="footer-left">
                <img src="img/MegClaro.png" alt="" class="logo-light" height="22">
                <img src="img/MegOscuro.png" alt="" class="logo-dark"  height="22">
                <span class="footer-sep">—</span>
                <span>Gestor de Archivos Seguro &nbsp;- &nbsp; Dayana Obando - UTPL - 2026</span>
            </div>
            <nav class="footer-links">
                <a href="#subir">Subir</a>
                <a href="#listado">Archivos</a>
                <a href="seguridad.php">Seguridad</a>
            </nav>
        </div>
    </div>
</footer>

<!-- ═══════════════════ MODAL: ELIMINAR ═══════════════════ -->
<div class="modal-overlay" id="modal-eliminar" role="dialog" aria-modal="true" aria-labelledby="mel-title">
    <div class="modal">
        <div class="modal-icon modal-icon-warning"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <h3 class="modal-title" id="mel-title">¿Eliminar archivo?</h3>
        <p class="modal-body">
            Estás a punto de eliminar <strong   id="mel-nombre">—</strong>
            Esta acción no se puede deshacer
        </p>
        <div class="modal-actions">
            <button type="button" class="btn btn-outline" onclick="cerrarModal('modal-eliminar')">Cancelar</button>
            <!-- Formulario POST: eliminar nunca debe hacerse con GET para no exponer el nombre en la URL -->
            <form id="form-eliminar" method="POST" action="eliminar.php" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="archivo" id="mel-input" value="">
                <button type="submit" class="btn btn-danger">
                    <i class="fa-solid fa-trash-can"></i> Sí, eliminar
                </button>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════════════ MODAL: RENOMBRAR ═══════════════════ -->
<div class="modal-overlay" id="modal-renombrar" role="dialog"
     aria-modal="true" aria-labelledby="modal-renombrar-title">
    <div class="modal">
        <div class="modal-icon">Renombrar</div>
        <h3 class="modal-title" id="modal-renombrar-title">Renombrar archivo</h3>
        <p class="modal-body" style="margin-bottom:16px;">
            El nombre en disco no cambia, solo el alias que ves en el listado.
        </p>
        <form method="POST" action="renombrar.php" class="rename-form">
            <input type="hidden" name="csrf_token"   value="<?= h($csrf) ?>">
            <input type="hidden" name="archivo"      id="input-renombrar-archivo" value="">
            <label for="alias-nuevo">Nuevo nombre visible</label>
            <input type="text" id="alias-nuevo" name="alias"
                   placeholder="mi-documento.pdf" maxlength="100" required>
            <div class="modal-actions" style="margin-top:4px;">
                <button type="button" class="btn btn-ghost btn-sm" onclick="cerrarModal('modal-renombrar')">
                    Cancelar
                </button>
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fa-solid fa-floppy-disk"></i> Guardar nombre
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const html = document.documentElement;
    const btn  = document.getElementById('themeToggle');
    html.setAttribute('data-theme', localStorage.getItem('theme') || 'dark');
    btn.addEventListener('click', () => {
        const next = html.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
        html.setAttribute('data-theme', next);
        localStorage.setItem('theme', next);
    });
})();

/**
 * Abre el modal de confirmación de eliminación.
 * @param {string} nombreArchivo Nombre en disco del archivo
 * @param {string} aliasArchivo  Alias visible del archivo
 */
function abrirConfirmar(nombreArchivo, aliasArchivo) {
    document.getElementById('mel-nombre').textContent = aliasArchivo || nombreArchivo;
    document.getElementById('mel-input').value         = nombreArchivo;
    document.getElementById('modal-eliminar').classList.add('open');
    document.body.style.overflow = 'hidden';
}

/**
 * Abre el modal de renombrado con el alias actual precargado.
 * @param {string} nombreArchivo  Nombre en disco
 * @param {string} aliasActual    Alias actual visible
 */
function abrirRenombrar(nombreArchivo, aliasActual) {
    document.getElementById('input-renombrar-archivo').value = nombreArchivo;
    document.getElementById('alias-nuevo').value             = aliasActual || nombreArchivo;
    document.getElementById('modal-renombrar').classList.add('open');
    document.getElementById('alias-nuevo').focus();
    document.body.style.overflow = 'hidden';
}


function cerrarModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
}

// Cerrar modales al hacer clic fuera del cuadro
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function (e) {
        if (e.target === this) {
            this.classList.remove('open');
            document.body.style.overflow = '';
        }
    });
});

// Cerrar modales con Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.open').forEach(m => {
            m.classList.remove('open');
            document.body.style.overflow = '';
        });
    }
});

// ── Nav link activo al hacer scroll ──────────────────────────────────
// En vez de IntersectionObserver (poco fiable cuando una sección es más alta que la ventana),
// se calcula directamente qué sección está debajo del header fijo según la posición actual del scroll.
const navLinks   = document.querySelectorAll('.nav-link');
const secSubir   = document.getElementById('subir');
const secListado = document.getElementById('listado');
const header     = document.querySelector('.site-header');

function marcarNavActivo(idActivo) {
    navLinks.forEach(link => {
        link.classList.toggle('active', link.getAttribute('href') === '#' + idActivo);
    });
}

function actualizarNavPorScroll() {
    if (!secSubir || !secListado) return;

    const alturaHeader = header ? header.offsetHeight : 0;
    // Punto de referencia: un poco por debajo del header fijo,
    // así la sección se marca justo cuando su título llega a la vista.
    const puntoReferencia = window.scrollY + alturaHeader + 10;

    // Mientras no se llegue al inicio de "Archivos", se mantiene "Subir" activo.
    // Al llegar (o pasar) el inicio de "Archivos", se marca "Archivos"
    if (puntoReferencia >= secListado.offsetTop) {
        marcarNavActivo('listado');
    } else {
        marcarNavActivo('subir');
    }
}

window.addEventListener('scroll', actualizarNavPorScroll, { passive: true });
window.addEventListener('resize', actualizarNavPorScroll);
actualizarNavPorScroll(); // estado inicial al cargar la página
</script>
