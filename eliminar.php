<?php
/**
 * Procesador de eliminación segura (solo POST)
 *
 * Sigue el mismo patrón PRG (Post/Redirect/Get) que subir.php y
 * renombrar.php, es decir, valida la petición, delega el trabajo real a
 * GestorArchivos::eliminar() y redirige a index.php con un mensaje flash.
 */
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>false,'httponly'=>true,'samesite'=>'Strict']);
session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

// Solo POST: eliminar nunca debe poder dispararse con un simple GET/enlace
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); header('Location: index.php'); exit;
}

// Verificar CSRF con hash_equals() — evita timing attacks
$tokenEnviado = $_POST['csrf_token'] ?? '';
$tokenSesion  = $_SESSION['csrf_token'] ?? '';
if (empty($tokenSesion) || !hash_equals($tokenSesion, $tokenEnviado)) {
    $_SESSION['msg_err'] = 'Error de seguridad: token CSRF inválido. Recarga la página.';
    header('Location: index.php'); exit;
}

$nombreArchivo = trim($_POST['archivo'] ?? '');
if (empty($nombreArchivo)) {
    $_SESSION['msg_err'] = 'No se especificó el archivo a eliminar.';
    header('Location: index.php'); exit;
}

require_once __DIR__ . '/GestorArchivos.php';

// Misma carpeta /uploads/ que usan subir.php, descargar.php y renombrar.php
$gestor    = new GestorArchivos(__DIR__ . '/uploads/');
$resultado = $gestor->eliminar($nombreArchivo);

if ($resultado['exito']) {

    // Eliminar también el alias guardado en sesión
    if (isset($_SESSION['alias_archivos'][$nombreArchivo])) {
        unset($_SESSION['alias_archivos'][$nombreArchivo]);
    }

    $_SESSION['msg_ok']  = $resultado['mensaje'];
} else {
    $_SESSION['msg_err'] = $resultado['mensaje'];
}

header('Location: index.php#listado'); exit;
 