<?php
/**
 * Zona horaria del servidor fija. Sin esto, date() usa el timezone
 * por defecto de PHP, lo que hace que la fecha y hora mostradas 
 * en el listado no coincidan con la hora local. Se define aquí 
 * porque GestorArchivos.php se incluye desde todos los puntos de 
 * entrada (index, subir, eliminar, renombrar, descargar, seguridad), 
 * así que basta con fijarlo una vez.
 */
date_default_timezone_set('America/Guayaquil');

/**
 * Clase principal
 *
 * PRINCIPIOS DE POO EN ESTA CLASE
 *
 * Encapsulamiento
 *   Propiedades: $directorio, $tamañoMaximo, $mimePermitidos y  $extensionesPermitidas 
 *  son private. Ningún archivo externo como index.php, subir.php,
 *   etc. puede leerlas ni modificarlas directamente, solo se accede
 *   a través de los métodos públicos de la clase. Esto evita que, por ejemplo,
 *   alguien cambie el directorio de subida desde afuera o desactive la validación
 *   de tamaño sin pasar por la lógica interna.
 *
 * Abstracción
 *   Quien usa la clase (new GestorArchivos(...) y luego ->subir(),
 *   ->listar(), ->eliminar()) no necesita saber cómo se valida un MIME,
 *   cómo se genera el hash del nombre o cómo se arma el .htaccess. Solo
 *   conoce el contrato público, o sea, qué parámetros recibe cada método y qué
 *   devuelve. Toda la complejidad de seguridad queda oculta dentro de la
 *   clase.
 *
 * Responsabilidad única
 *   La clase hace una sola cosa y es gestionar archivos del directorio
 *   /uploads de forma segura. Esa separación es lo que permite
 *   reutilizar la misma clase en index.php, en seguridad.php y en
 *   cualquier procesador = {subir.php, eliminar.php, renombrar.php,
 *   descargar.php} sin duplicar lógica.
 *
 * Métodos privados como apoyo interno
 *   crearHtaccess(), mimeCoherenteConExtension(), respuesta(),
 *   formatearTamaño() y mensajeError() son private porque son detalles
 *   de implementación: ayudan a los métodos públicos a hacer su trabajo,
 *   pero no tiene sentido que se llamen desde fuera de la clase.
 *
 * Método estático
 *   obtenerAlias() es static porque no depende del estado de ningún
 *   objeto en particular (no usa $this->directorio ni nada propio de
 *   una instancia), solo consulta un dato de sesión. Por eso se llama
 *   como GestorArchivos::obtenerAlias(...) sin necesidad de instanciar.
 *
 * MEDIDAS DE SEGURIDAD IMPLEMENTADAS EN ESTA CLASE
 *  - Validación de tipo MIME real con finfo (nunca confía en $_FILES['type'])
 *  - Lista blanca de extensiones: solo PDF, JPG, PNG
 *  - Coherencia MIME - extensión (previene archivos políglotos)
 *  - Límite de tamaño: 3 MB
 *  - Renombrado automático con hash SHA-256 + timestamp (evita ejecución y colisiones IMPORTANTE)
 *  - Prevención de Path Traversal: basename() + realpath() + strpos()
 *  - .htaccess autogenerado en /uploads/ que bloquea ejecución de scripts
 *  - Cabeceras de descarga seguras: Content-Type: application/octet-stream
 *  - Permisos de archivo restrictivos: 644 tras la subida
 * /Todos estos estos datos se recaban a partir de las indicaciones y ejemplos de páginas de seguridad 
 *  web como OWASP, PHP Security Guide y otros recursos de buenas prácticas.
 */
class GestorArchivos
{
    /**
     * Encapsulamiento: ruta del directorio de almacenamiento.
     * Private ningún código externo debe cambiar a qué carpeta apunta esta instancia
     * @var string
     */
    private string $directorio;

    /**
     * Encapsulamiento: límite de tamaño en bytes privado impide que un script externo lo aumente
     * para saltarse la validación de subir().
     * @var int
     */
    private int $tamañoMaximo;

    /** @var array<string> Tipos MIME aceptados — whitelist */
    private array $mimePermitidos = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];

    /** @var array<string> Extensiones aceptadas — whitelist secundaria */
    private array $extensionesPermitidas = ['pdf', 'jpg', 'jpeg', 'png'];

    // ─────────────────────────────────────────────────────────────────────
    // CONSTRUCTOR
    // ─────────────────────────────────────────────────────────────────────

    /**
     * El constructor es el único lugar donde se inicializa el estado
     * interno del objeto (abstracción de la configuración: quien crea
     * el objeto solo pasa una ruta y, un límite de tamaño,
     * sin tener que preocuparse de crear la carpeta o protegerla).
     *
     * @param string $directorio   Ruta al directorio /uploads
     * @param int    $tamañoMaximo Límite de tamaño en bytes
     */
    public function __construct(string $directorio, int $tamañoMaximo = 3145728)
    {
        // realpath() resuelve la ruta absoluta real, previniendo traversal en config
        $this->directorio   = rtrim(realpath($directorio) ?: $directorio, DIRECTORY_SEPARATOR)
                              . DIRECTORY_SEPARATOR;
        $this->tamañoMaximo = $tamañoMaximo;

        if (!is_dir($this->directorio)) {
            mkdir($this->directorio, 0755, true);
        }

        // crearHtaccess() es un método privado: el objeto se protege a sí
        // mismo al nacer, sin que quien lo instancia tenga que acordarse
        // de hacerlo manualmente
        $this->crearHtaccess();
    }

    // ─────────────────────────────────────────────────────────────────────
    // MÉTODO PÚBLICO: subir()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Abstracción: quien llama a este método (subir.php) solo entrega el
     * arreglo $_FILES['archivo'] y recibe un resultado con éxito o error.
     * No necesita conocer ni el orden de las ocho validaciones internas
     * ni cómo se construye el nombre seguro: esa secuencia vive aquí,
     * encerrada dentro del objeto.
     *
     * @param  array $archivo Elemento de $_FILES['archivo']
     * @return array ['exito' => bool, 'mensaje' => string, 'nombre_guardado' => string|null]
     */
    public function subir(array $archivo): array
    {
        // 1. Código de error de PHP al recibir el archivo
        if ($archivo['error'] !== UPLOAD_ERR_OK) {
            return $this->respuesta(false, $this->mensajeError($archivo['error']));
        }

        // 2. Validar tamaño máximo, usando la propiedad privada $tamañoMaximo
        //    en vez de un número suelto. Si en el futuro se quiere cambiar
        //    el límite, basta con tocar el constructor, no cada validación.
        if ($archivo['size'] > $this->tamañoMaximo) {
            $maxMB = round($this->tamañoMaximo / 1048576, 1);
            return $this->respuesta(false, "El archivo supera el límite de {$maxMB} MB.");
        }

        // 3. Detectar MIME real con finfo ignora la cabecera HTTP manipulable
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeReal = $finfo->file($archivo['tmp_name']);

        if (!in_array($mimeReal, $this->mimePermitidos, true)) {
            return $this->respuesta(false, 'Tipo de archivo no permitido. Solo PDF, JPG y PNG.');
        }

        // 4. Validar extensión del nombre original
        $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $this->extensionesPermitidas, true)) {
            return $this->respuesta(false, 'Extensión no permitida.');
        }

        // 5. Coherencia MIME - extensión: previene archivos políglotos
        //    (ej. un PHP disfrazado de .jpg). Se delega a un método privado
        //    porque es un detalle de implementación, no algo que el resto
        //    de la aplicación necesite invocar por su cuenta.
        if (!$this->mimeCoherenteConExtension($mimeReal, $extension)) {
            return $this->respuesta(false, 'El contenido del archivo no coincide con su extensión.');
        }

        // 6. Renombrar con hash SHA-256 del contenido + timestamp
        //    Formato: {sha256}_{timestamp}.ext
        //    Razones: evita nombres maliciosos, evita colisiones, imposible adivinar la ruta
        $hash         = hash_file('sha256', $archivo['tmp_name']);
        $nombreSeguro = $hash . '_' . time() . '.' . $extension;
        $rutaDestino  = $this->directorio . $nombreSeguro;

        // 7. Mover el archivo desde el directorio temporal al destino protegido
        if (!move_uploaded_file($archivo['tmp_name'], $rutaDestino)) {
            return $this->respuesta(false, 'Error al guardar el archivo en el servidor.');
        }

        // 8. Aplicar permisos restrictivos: lectura/escritura owner, solo lectura resto
        chmod($rutaDestino, 0644);

        return $this->respuesta(true, 'Archivo subido exitosamente.', $nombreSeguro);
    }

    // ─────────────────────────────────────────────────────────────────────
    // MÉTODO PÚBLICO: listar()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Devuelve el listado de todos los archivos almacenados en /uploads.
     * Encapsulamiento en acción: este método es el único lugar de toda
     * la aplicación que recorre el directorio con DirectoryIterator.
     * index.php y seguridad.php solo llaman a $gestor->listar() y reciben
     * un arreglo ya limpio, ninguno de los dos sabe (ni necesita saber)
     * que por dentro se está iterando el sistema de archivos.
     *
     * @return array<array{nombre: string, tamaño: string, bytes: int, fecha: string}>
     */
    public function listar(): array
    {
        $archivos = [];

        foreach (new DirectoryIterator($this->directorio) as $item) {
            // Omitir directorios, puntos (. / ..) y el .htaccess de protección
            if ($item->isDot() || $item->isDir() || $item->getFilename() === '.htaccess') {
                continue;
            }

            $archivos[] = [
                'nombre'    => $item->getFilename(),
                'tamaño'    => $this->formatearTamaño($item->getSize()),
                'bytes'     => $item->getSize(),
                'fecha'     => date('d/m/Y H:i', $item->getMTime()),
                'timestamp' => $item->getMTime(),
            ];
        }

        // Ordenar por fecha de modificación: más reciente primero
        usort($archivos, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);

        return $archivos;
    }

    // ─────────────────────────────────────────────────────────────────────
    // MÉTODO PÚBLICO: eliminar()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Elimina un archivo de forma segura, previniendo Path Traversal.
     * Note cómo $this->directorio (propiedad privada fijada en el
     * constructor) es la base contra la que se valida cada ruta. Como
     * nadie fuera de la clase puede sobrescribir esa propiedad, la
     * comparación de strpos() en el paso 4 es confiable: siempre se
     * está comparando contra la carpeta real con la que se instanció
     * el objeto, no contra un valor que alguien pudo haber alterado
     *
     * @param  string $nombre Nombre del archivo a eliminar
     * @return array  ['exito' => bool, 'mensaje' => string]
     */
    public function eliminar(string $nombre): array
    {
        // 1. basename() elimina cualquier componente de ruta (../, /, etc.)
        $nombreLimpio = basename($nombre);

        // 2. Validar que el nombre corresponde al patrón de renombrado seguro
        if (!preg_match('/^[a-f0-9]+_\d+\.(pdf|jpg|jpeg|png)$/i', $nombreLimpio)) {
            return $this->respuesta(false, 'Nombre de archivo inválido.');
        }

        // 3. realpath() resuelve la ruta absoluta real y devuelve false si no existe
        $rutaArchivo = $this->directorio . $nombreLimpio;
        $rutaReal    = realpath($rutaArchivo);

        // 4. strpos() verifica que el archivo está DENTRO del directorio autorizado
        //    (segunda capa de defensa contra Path Traversal)
        if ($rutaReal === false || strpos($rutaReal, $this->directorio) !== 0) {
            return $this->respuesta(false, 'Acceso no autorizado al archivo.');
        }

        if (!is_file($rutaReal)) {
            return $this->respuesta(false, 'El archivo no existe.');
        }

        if (!unlink($rutaReal)) {
            return $this->respuesta(false, 'No se pudo eliminar el archivo.');
        }

        return $this->respuesta(true, 'Archivo eliminado correctamente.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // MÉTODO PÚBLICO: renombrar()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Renombra el archivo de display (alias) guardando la asociación en sesión.
     * El archivo en disco conserva su nombre seguro (SHA-256) solo el alias
     * que se muestra al usuario cambia.
     *
     * Los pasos 1 y 2 repiten la misma validación anti-traversal
     * que eliminar(): basename() + el mismo patrón con preg_match() +
     * realpath() + strpos(). Es la regla de seguridad de la clase aplicada
     * de forma consistente en cada método que recibe un nombre de archivo
     * desde fuera, no solo en uno de ellos
     *
     * @param  string $nombreActual  Nombre seguro actual del archivo en disco
     * @param  string $aliasNuevo   Alias legible que el usuario quiere ver
     * @return array  ['exito' => bool, 'mensaje' => string]
     */
    public function renombrar(string $nombreActual, string $aliasNuevo): array
    {
        // 1. Sanitizar el nombre actual (anti-traversal)
        $nombreLimpio = basename($nombreActual);
        if (!preg_match('/^[a-f0-9]+_\d+\.(pdf|jpg|jpeg|png)$/i', $nombreLimpio)) {
            return $this->respuesta(false, 'Archivo no válido.');
        }

        // 2. Verificar que el archivo existe en disco
        $rutaReal = realpath($this->directorio . $nombreLimpio);
        if ($rutaReal === false || strpos($rutaReal, $this->directorio) !== 0 || !is_file($rutaReal)) {
            return $this->respuesta(false, 'El archivo no existe en el servidor.');
        }

        // 3. Sanitizar el alias: solo alfanuméricos, guiones, guiones bajos y punto
        $aliasSanitizado = preg_replace('/[^a-zA-Z0-9._\- ]/', '', $aliasNuevo);
        $aliasSanitizado = trim($aliasSanitizado);

        if ($aliasSanitizado === '') {
            return $this->respuesta(false, 'El nombre no puede estar vacío o contener solo caracteres especiales.');
        }

        if (strlen($aliasSanitizado) > 100) {
            return $this->respuesta(false, 'El nombre no puede superar los 100 caracteres.');
        }

        // 4. Guardar el alias en sesión: [nombre_en_disco => alias_visible]
        if (!isset($_SESSION)) { session_start(); }
        $_SESSION['alias_archivos'][$nombreLimpio] = $aliasSanitizado;

        return $this->respuesta(true, "Archivo renombrado a «{$aliasSanitizado}» correctamente.");
    }

    // ─────────────────────────────────────────────────────────────────────
    // MÉTODO PÚBLICO: descargar()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Envía el archivo al navegador como descarga forzada.
     * Establece cabeceras que impiden la ejecución en el navegador.
     *
     * @param string $nombre Nombre del archivo en disco
     */
    public function descargar(string $nombre): void
    {
        $nombreLimpio = basename($nombre);

        if (!preg_match('/^[a-f0-9]+_\d+\.(pdf|jpg|jpeg|png)$/i', $nombreLimpio)) {
            http_response_code(400);
            exit('Solicitud inválida.');
        }

        $rutaReal = realpath($this->directorio . $nombreLimpio);

        if ($rutaReal === false || strpos($rutaReal, $this->directorio) !== 0 || !is_file($rutaReal)) {
            http_response_code(404);
            exit('Archivo no encontrado.');
        }

        // Usar el alias si existe, si no el nombre en disco
        $nombreDescarga = $_SESSION['alias_archivos'][$nombreLimpio] ?? $nombreLimpio;

        // Cabeceras de descarga segura
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');               // Fuerza descarga, NUNCA ejecución
        header('Content-Disposition: attachment; filename="' . $nombreDescarga . '"');
        header('Content-Length: ' . filesize($rutaReal));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');                      // Previene MIME sniffing

        readfile($rutaReal);
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────
    // MÉTODO ESTÁTICO: obtenerAlias()
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Devuelve el alias visible de un archivo, o el nombre en disco si no tiene alias.
     * Es static porque solo lee un valor de $_SESSION, así que se puede llamar directamente
     * sobre la clase, sin pasar por new GestorArchivos(...) primero. Por
     * eso index.php y seguridad.php la invocan como
     * GestorArchivos::obtenerAlias($nombre) en vez de $gestor->obtenerAlias($nombre).
     *
     * @param string $nombreEnDisco Nombre del archivo en /uploads
     * @return string
     */
    public static function obtenerAlias(string $nombreEnDisco): string
    {
        return $_SESSION['alias_archivos'][$nombreEnDisco] ?? $nombreEnDisco;
    }

    // ═════════════════════════════════════════════════════════════════════
    // MÉTODOS PRIVADOS DE APOYO
    //
    // Todos los métodos de esta sección son private: existen únicamente
    // para que los métodos públicos de arriba (subir, eliminar, renombrar)
    // no repitan código. Ningún archivo fuera de esta clase los necesita
    // ni puede llamarlos directamente; intentar $gestor->respuesta(...)
    // desde index.php produciría un error de PHP. Esa restricción es
    // intencional
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Crea el .htaccess de protección en /uploads/.
     * Bloquea la ejecución de PHP y cualquier otro script server-side.
     * Desactiva el listado del directorio.
     */
    private function crearHtaccess(): void
    {
        $htaccess = $this->directorio . '.htaccess';
        if (!file_exists($htaccess)) {
            $contenido = <<<HTACCESS
# ── Seguridad: bloquear ejecución de scripts en /uploads/ ──
<FilesMatch "\.(php|php3|php4|php5|phtml|pl|py|cgi|sh|rb|asp|aspx|jsp)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order Deny,Allow
        Deny from all
    </IfModule>
</FilesMatch>

# Deshabilitar SSI y CGI
Options -Includes -ExecCGI

# Deshabilitar listado del directorio
Options -Indexes
HTACCESS;
            file_put_contents($htaccess, $contenido);
        }
    }   

    /**
     * Verifica que el tipo MIME real coincide con la extensión declarada.
     * Previene archivos políglotos: un PHP renombrado a .jpg seguiría teniendo
     * MIME image/jpeg si se usara solo la cabecera, pero finfo detecta el tipo real
     */
    private function mimeCoherenteConExtension(string $mime, string $ext): bool
    {
        $mapa = [
            'pdf'  => 'application/pdf',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
        ];
        return isset($mapa[$ext]) && $mapa[$ext] === $mime;
    }

    /** Constructor de array de respuesta estándar */
    private function respuesta(bool $exito, string $mensaje, ?string $nombreGuardado = null): array
    {
        return ['exito' => $exito, 'mensaje' => $mensaje, 'nombre_guardado' => $nombreGuardado];
    }

    /** Convierte bytes en cadena legible: KB o MB */
    private function formatearTamaño(int $bytes): string
    {
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024)    return round($bytes / 1024, 1)   . ' KB';
        return $bytes . ' B';
    }

    /** Traduce los códigos de error de $_FILES a mensajes en español */
    private function mensajeError(int $codigo): string
    {
        return [
            UPLOAD_ERR_INI_SIZE   => 'El archivo supera el tamaño máximo del servidor (upload_max_filesize).',
            UPLOAD_ERR_FORM_SIZE  => 'El archivo supera el tamaño máximo del formulario.',
            UPLOAD_ERR_PARTIAL    => 'El archivo se subió parcialmente. Intenta de nuevo.',
            UPLOAD_ERR_NO_FILE    => 'No se seleccionó ningún archivo.',
            UPLOAD_ERR_NO_TMP_DIR => 'Error del servidor: directorio temporal no disponible.',
            UPLOAD_ERR_CANT_WRITE => 'Error del servidor: no se pudo escribir el archivo.',
            UPLOAD_ERR_EXTENSION  => 'La subida fue bloqueada por una extensión del servidor.',
        ][$codigo] ?? 'Error desconocido al subir el archivo.';
    }
}
