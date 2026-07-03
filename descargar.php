<?php
/**
 * Descarga segura de archivos (GET con CSRF)
 * sirve archivos con Content-Type: application/octet-stream
 * nunca permite la ejecución en el navegador.
 *
 * Unico procesador que llama a un método que no devuelve un
 * arreglo, sino que termina la petición directamente con exit (ver
 * GestorArchivos::descargar). Aun así, el archivo sigue el mismo patrón:
 * crear el objeto y delegarle el trabajo completo.
 */
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>false,'httponly'=>true,'samesite'=>'Strict']);
session_start();

$tokenEnviado = $_GET['csrf_token'] ?? '';
$tokenSesion  = $_SESSION['csrf_token'] ?? '';
if (empty($tokenSesion) || !hash_equals($tokenSesion, $tokenEnviado)) {
    http_response_code(403); exit('Acceso denegado: token inválido.');
}

$nombre = trim($_GET['archivo'] ?? '');
if (empty($nombre)) {
    http_response_code(400); exit('Nombre de archivo no especificado.');
}

require_once __DIR__ . '/GestorArchivos.php';
$gestor = new GestorArchivos(__DIR__ . '/uploads/');
$gestor->descargar($nombre);
