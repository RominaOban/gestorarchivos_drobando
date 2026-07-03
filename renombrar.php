<?php
/**
 * Procesador de renombrado segura (solo POST)
 * El archivo en disco NO cambia, solo se actualiza el alias en sesión.
 * Cuarto procesador con la misma estructura que subir.php, eliminar.php
 * y descargar.php: instanciar GestorArchivos y llamar a un único método
 * público. Esa repetición del mismo patrón en los procesadores es
 * justamente lo que demuestra la ventaja de encapsular la lógica en una
 * clase: el "cómo" cambia según el método, pero el "dónde vive ese cómo"
 * siempre es el mismo objeto (POO).
 */
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>false,'httponly'=>true,'samesite'=>'Strict']);
session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); header('Location: index.php'); exit;
}

$tokenEnviado = $_POST['csrf_token'] ?? '';
$tokenSesion  = $_SESSION['csrf_token'] ?? '';
if (empty($tokenSesion) || !hash_equals($tokenSesion, $tokenEnviado)) {
    $_SESSION['msg_err'] = 'Error de seguridad: token CSRF inválido.';
    header('Location: index.php'); exit;
}

$archivo = trim($_POST['archivo'] ?? '');
$alias   = trim($_POST['alias']   ?? '');

if (empty($archivo)) {
    $_SESSION['msg_err'] = 'No se especificó el archivo a renombrar.';
    header('Location: index.php'); exit;
}

require_once __DIR__ . '/GestorArchivos.php';
$gestor    = new GestorArchivos(__DIR__ . '/uploads/');
$resultado = $gestor->renombrar($archivo, $alias);

if ($resultado['exito']) {
    $_SESSION['msg_ok']  = $resultado['mensaje'];
} else {
    $_SESSION['msg_err'] = $resultado['mensaje'];
}

header('Location: index.php#listado'); exit;
