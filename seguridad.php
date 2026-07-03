<?php
/**
 * Página dedicada a explicar las medidas de seguridad del sistema.
 *
 * Se separó de index.php para que la pantalla principal se mantenga
 * enfocada en la tarea (subir / ver / eliminar) y esta página sirva
 * como referencia técnica,para usted tutora y para auditar el código.
 * PRINCIPIOS POO APLICADOS EN ESTE ARCHIVO:
 *   Instanciación: se crea un GestorArchivos para reutilizar listar()
 *   y mostrar estadísticas reales de lo que hay en /uploads.
 *   Esto evita duplicar la lógica de recorrido de directorio: la regla
 *   "cómo se cuenta un archivo" vive dentro de la clase.
 */

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => false,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src https://fonts.gstatic.com https://cdnjs.cloudflare.com; script-src 'self' 'unsafe-inline';");

require_once __DIR__ . '/GestorArchivos.php';

// Reutilizamos el mismo objeto y el mismo método listar() que usa index.php.
// Esto es reutilización de comportamiento por objeto, el mismo método público
// sirve para dos contextos distintos (listar para mostrar la tabla, listar
// para contar estadísticas) sin que la clase necesite saber para qué se usa
// su resultado en cada página.
$gestor   = new GestorArchivos(__DIR__ . '/uploads/');
$archivos = $gestor->listar();

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// EXTRA: eso son las estadísticas por tipo de archivo
// Desglosa cuántos archivos hay de cada tipo y cuánto espacio ocupa cada categoría,
// calculado a partir de los mismos datos que ya devuelve $gestor->listar().
$conteoPorTipo  = ['pdf' => 0, 'jpg' => 0, 'png' => 0];
$bytesPorTipo   = ['pdf' => 0, 'jpg' => 0, 'png' => 0];

foreach ($archivos as $f) {
    $ext = strtolower(pathinfo($f['nombre'], PATHINFO_EXTENSION));
    $key = ($ext === 'jpeg') ? 'jpg' : $ext;
    if (isset($conteoPorTipo[$key])) {
        $conteoPorTipo[$key]++;
        $bytesPorTipo[$key] += $f['bytes'];
    }
}

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
    <title>Seguridad — Gestor de Archivos Seguro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,600;1,9..144,300&family=Space+Mono:ital,wght@0,400;0,700;1,400&family=Plus+Jakarta+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>✦</text></svg>">
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
                <a href="index.php#subir"   class="nav-link"><i class="fa-solid fa-upload"></i><span>Subir</span></a>
                <a href="index.php#listado" class="nav-link"><i class="fa-solid fa-folder-open"></i><span>Archivos</span></a>
                <a href="seguridad.php" class="nav-link active"><i class="fa-solid fa-shield-halved"></i><span>Seguridad</span></a>
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
    <section class="page-header">
        <div class="container">
            <div class="hero-eyebrow">referencia técnica</div>
            <h1>Medidas de <em>seguridad</em></h1>
            <p>
                Cada operación de subida, listado y eliminación pasa por varias
                capas de validación. Aquí se explica qué hace cada una y por qué
                es necesaria.
            </p>
        </div>
    </section>

    <div class="container page-sections" style="padding-top:32px; padding-bottom:80px;">

        <!-- ── EXTRA, las estadísticas por tipo de archivo ──────────────── -->
        <section class="card" aria-labelledby="h-stats">
            <div class="card-header">
                <span class="card-header-icon"><i class="fa-solid fa-chart-pie"></i></span>
                <h2 id="h-stats">Desglose por tipo de archivo</h2>
            </div>
            <div class="card-body">
                <div class="sec-grid">
                    <div class="sec-item">
                        <i class="fa-solid fa-file-pdf"></i>
                        <div>
                            <strong>PDF</strong>
                            <span><?= $conteoPorTipo['pdf'] ?> archivo<?= $conteoPorTipo['pdf'] !== 1 ? 's' : '' ?>
                                &nbsp;·&nbsp; <?= formatBytes($bytesPorTipo['pdf']) ?></span>
                        </div>
                    </div>
                    <div class="sec-item">
                        <i class="fa-solid fa-file-image"></i>
                        <div>
                            <strong>JPG</strong>
                            <span><?= $conteoPorTipo['jpg'] ?> archivo<?= $conteoPorTipo['jpg'] !== 1 ? 's' : '' ?>
                                &nbsp;·&nbsp; <?= formatBytes($bytesPorTipo['jpg']) ?></span>
                        </div>
                    </div>
                    <div class="sec-item">
                        <i class="fa-solid fa-file-image"></i>
                        <div>
                            <strong>PNG</strong>
                            <span><?= $conteoPorTipo['png'] ?> archivo<?= $conteoPorTipo['png'] !== 1 ? 's' : '' ?>
                                &nbsp;·&nbsp; <?= formatBytes($bytesPorTipo['png']) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ── Validación de subida ──────────────────────────────────── -->
        <section class="card" aria-labelledby="h-subida">
            <div class="card-header">
                <span class="card-header-icon"><i class="fa-solid fa-cloud-arrow-up"></i></span>
                <h2 id="h-subida">Al subir un archivo</h2>
            </div>
            <div class="card-body">
                <div class="sec-grid">
                    <div class="sec-item">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <div>
                            <strong>MIME real con finfo</strong>
                            <span>Se detecta el tipo real del archivo leyendo sus primeros bytes, en lugar de confiar en la cabecera HTTP que envía el navegador y que cualquiera puede falsificar</span>
                        </div>
                    </div>
                    <div class="sec-item">
                        <i class="fa-solid fa-list-check"></i>
                        <div>
                            <strong>Lista blanca de tipos</strong>
                            <span>Solo PDF, JPG y PNG son aceptados. Cualquier otra extensión o tipo MIME se rechaza antes de tocar el disco</span>
                        </div>
                    </div>
                    <div class="sec-item">
                        <i class="fa-solid fa-puzzle-piece"></i>
                        <div>
                            <strong>Coherencia MIME <-> extensión</strong>
                            <span>El tipo detectado y la extensión del nombre deben coincidir, lo que evita archivos políglotos (un .php disfrazado de .jpg)</span>
                        </div>
                    </div>
                    <div class="sec-item">
                        <i class="fa-solid fa-ruler"></i>
                        <div>
                            <strong>Límite de tamaño</strong>
                            <span>Archivos mayores a 3 MB se rechazan antes de ser procesados, evitando saturar el almacenamiento o la memoria del servidor</span>
                        </div>
                    </div>
                    <div class="sec-item">
                        <i class="fa-solid fa-shuffle"></i>
                        <div>
                            <strong>Renombrado SHA-256</strong>
                            <span>El archivo se guarda en disco con un hash más timestamp, nunca con el nombre original. Esto hace imposible adivinar la ruta de otro archivo y evita ejecutar código por nombre</span>
                        </div>
                    </div>
                    <div class="sec-item">
                        <i class="fa-solid fa-file-shield"></i>
                        <div>
                            <strong>.htaccess en /uploads/</strong>
                            <span>El directorio de almacenamiento bloquea la ejecución de PHP y otros scripts, incluso si alguien lograra subir uno</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ── Listado y eliminación ─────────────────────────────────── -->
        <section class="card" aria-labelledby="h-gestion">
            <div class="card-header">
                <span class="card-header-icon"><i class="fa-solid fa-folder-open"></i></span>
                <h2 id="h-gestion">Al listar, descargar y eliminar</h2>
            </div>
            <div class="card-body">
                <div class="sec-grid">
                    <div class="sec-item">
                        <i class="fa-solid fa-ban"></i>
                        <div>
                            <strong>Anti Path Traversal</strong>
                            <span>basename() elimina cualquier ../ del nombre recibido, y realpath() + strpos() confirman que la ruta final sigue dentro de /uploads/</span>
                        </div>
                    </div>
                    <div class="sec-item">
                        <i class="fa-solid fa-download"></i>
                        <div>
                            <strong>Descarga forzada</strong>
                            <span>Las descargas se sirven con Content-Type: application/octet-stream, así el navegador nunca intenta ejecutar el archivo, solo lo descarga</span>
                        </div>
                    </div>
                    <div class="sec-item">
                        <i class="fa-solid fa-trash-can"></i>
                        <div>
                            <strong>Confirmación antes de eliminar</strong>
                            <span>Un modal pide confirmar el nombre del archivo antes de enviar el formulario, evitando eliminaciones accidentales</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ── Protección general del sitio ──────────────────────────── -->
        <section class="card" aria-labelledby="h-general">
            <div class="card-header">
                <span class="card-header-icon"><i class="fa-solid fa-lock"></i></span>
                <h2 id="h-general">Protección general del sitio</h2>
            </div>
            <div class="card-body">
                <div class="sec-grid">
                    <div class="sec-item">
                        <i class="fa-solid fa-key"></i>
                        <div>
                            <strong>Token CSRF</strong>
                            <span>random_bytes(32) genera un token único por sesión, y hash_equals() lo compara de forma segura en cada formulario antes de aceptar la acción</span>
                        </div>
                    </div>
                    <div class="sec-item">
                        <i class="fa-solid fa-code"></i>
                        <div>
                            <strong>Anti XSS</strong>
                            <span>Toda variable que se imprime en el HTML pasa por htmlspecialchars(ENT_QUOTES), incluyendo nombres de archivo y mensajes de error</span>
                        </div>
                    </div>
                    <div class="sec-item">
                        <i class="fa-solid fa-cookie-bite"></i>
                        <div>
                            <strong>Cookies HttpOnly + SameSite</strong>
                            <span>La cookie de sesión es inaccesible desde JavaScript (HttpOnly) y no se envía en peticiones de otros sitios (SameSite=Strict)</span>
                        </div>
                    </div>
                    <div class="sec-item">
                        <i class="fa-solid fa-shield-halved"></i>
                        <div>
                            <strong>Cabeceras HTTP</strong>
                            <span>X-Frame-Options bloquea iframes (clickjacking), X-Content-Type-Options evita que el navegador reinterprete tipos MIME, y CSP restringe qué recursos pueden cargarse</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

    </div>
</main>

<!-- ═══════════════════ FOOTER ═══════════════════ -->
<footer class="site-footer">
    <div class="container">
        <div class="footer-inner">
            <div class="footer-left">
                <img src="img/MegClaro.png" alt="" class="logo-light" height="22">
                <img src="img/MegOscuro.png" alt="" class="logo-dark"  height="22">
                <span class="footer-sep">—</span>
                <span>Gestor de Archivos Seguro &nbsp;·&nbsp; Dayana Obando - UTPL - 2026</span>
            </div>
            <nav class="footer-links">
                <a href="index.php#subir">Subir</a>
                <a href="index.php#listado">Archivos</a>
                <a href="seguridad.php">Seguridad</a>
            </nav>
        </div>
    </div>
</footer>

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
</script>
</body>
</html>
