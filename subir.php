<?php
/**
 *  Procesador de subida (solo POST)
 *
 * Este archivo no contiene NINGUNA lógica de validación de archivos.
 * Toda esa responsabilidad vive dentro de GestorArchivos (principio de
 * responsabilidad única): aquí solo se verifica que la petición sea
 * válida (POST + CSRF correcto) y luego se delega el trabajo real al
 * objeto. Patrón PRG: siempre redirige a index.php con un mensaje flash.
 */
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>false,'httponly'=>true,'samesite'=>'Strict']);
session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

// Solo POST
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

if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] === UPLOAD_ERR_NO_FILE) {
    $_SESSION['msg_err'] = 'No seleccionaste ningún archivo.';
    header('Location: index.php'); exit;
}

require_once __DIR__ . '/GestorArchivos.php';

// Instanciación: se crea un objeto nuevo en cada petición HTTP, apuntando
// siempre a /uploads/. El objeto vive solo durante esta petición y se
// descarta al terminar el script; no hay estado compartido entre usuarios
$gestor = new GestorArchivos(__DIR__ . '/uploads/');

// Toda la validación de MIME, extensión, tamaño y el renombrado seguro
// ocurre dentro de este único método. Este archivo solo lee el resultado.
$resultado = $gestor->subir($_FILES['archivo']);

if ($resultado['exito']) {
    $_SESSION['msg_ok'] = ' ' . $resultado['mensaje']
        . ' Puedes renombrarlo con el botón del listado.';
} else {
    $_SESSION['msg_err'] = $resultado['mensaje'];
}

header('Location: index.php#listado'); exit;
