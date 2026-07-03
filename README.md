# Gestor de Archivos Seguro — PHP OOP

**Actividad B2 — Seguridad y Vulnerabilidades**
**Autora:** Dayana Obando 

---

## 1. Descripción del sistema

Módulo web para subida, visualización y eliminación segura de archivos PDF, JPG y PNG, desarrollado en PHP con Programación Orientada a Objetos. No requiere inicio de sesión: cualquier persona que acceda a `index.php` puede usar el módulo directamente, tal como pide la actividad, y como se dice en la reunión de la actividad.

Funcionalidades:

- Subida con validación de tipo MIME real, extensión y tamaño máximo de 3 MB
- Listado con nombre, tamaño y fecha de subida
- Renombrado de alias visible tras la subida (el archivo en disco conserva su nombre seguro)
- Eliminación con modal de confirmación antes de borrar
- Descarga forzada, nunca ejecutada en el navegador
- Página de seguridad separada (seguridad.php) con la explicación de cada medida y un desglose de archivos por tipo
- Modo oscuro y claro con persistencia en localStorage

---

## 2. Estructura de archivos

```
gestor_b2/
├── index.php          Página principal: formulario de subida + listado
├── seguridad.php       Página separada con la explicación de seguridad
├── subir.php           Procesador de subida (POST)
├── eliminar.php         Procesador de eliminación (POST)
├── renombrar.php        Procesador del alias visible (POST)
├── descargar.php        Descarga segura (GET con token CSRF)
├── GestorArchivos.php   Clase PHP principal
├── css/
│   └── style.css        Estilos propios (reutilizado del portafolio)
├── img/
│   ├── MegClaro.png      Logo modo claro
│   └── MegOscuro.png     Logo modo oscuro
├── uploads/              Archivos almacenados (protegidos)
│   └── .htaccess          Bloquea ejecución de scripts (autogenerado)
└── README.md
```

---

## 3. Instrucciones de uso

### Requisitos

- PHP 8.0 o superior
- Extensión fileinfo habilitada (activa por defecto en la mayoría de instalaciones!)
- Servidor web: Apache, Nginx o el servidor integrado de PHP

### Instalación y arranque

1. Clonar el repositorio con Git:
   git clone <URL_DEL_REPOSITORIO>

2. Mover la carpeta del proyecto al directorio `htdocs` de XAMPP.
   Ejemplo:
   C:\xampp\htdocs\gestorarchivos_drobando

3. Iniciar los servicios Apache (y MySQL si el proyecto utiliza base de datos) desde el Panel de Control de XAMPP.

4. Abrir el navegador y acceder a:
   http://localhost/gestorarchivos_drobando/

### Subir un archivo

1. Abrir index.php
2. Seleccionar un archivo PDF, JPG o PNG de hasta 3 MB
3. Pulsar "Subir archivo"
4. El sistema muestra un mensaje de éxito y sugiere renombrarlo si se desea

### Renombrar un archivo

1. En el listado, pulsar "Renombrar" junto al archivo
2. Escribir el nuevo nombre visible en el formulario emergente
3. Confirmar. El nombre en disco no cambia, solo el alias que se ve en pantalla

### Eliminar un archivo

1. En el listado, pulsar "Eliminar"
2. Confirmar en el cuadro de diálogo que aparece
3. El archivo se elimina permanentemente del servidor

---

## 4. La clase GestorArchivos

**Archivo:** GestorArchivos.php

Es la única clase del proyecto y concentra toda la lógica de negocio y de seguridad. Ningún otro archivo del sistema manipula directamente el sistema de archivos, es decier, todos pasan por esta clase.

### Principios de Programación Orientada a Objetos aplicados

**Encapsulamiento**

Las propiedades:
- $directorio
- $tamañoMaximo
- $mimePermitidos
- $extensionesPermitidas 
Son private. Ningún archivo externo puede leerlas ni modificarlas directamente, solo se accede a través de los métodos públicos. 

**Abstracción**

Quien usa la clase:
- index.php
- subir.php
- eliminar.php, etc.
Solo conoce el contrato público: qué parámetros recibe cada método y qué devuelve. Toda la complejidad de seguridad queda oculta dentro de la clase.

**Responsabilidad única**

La clase hace una sola cosa: 
- Gestionar archivos de un directorio de forma segura. Esa separación es lo que permite reutilizar la misma clase tanto en index.php como en seguridad.php, y en cada uno de los procesadores, sin duplicar lógica de validación.

**Métodos privados como apoyo interno**
- crearHtaccess()
- mimeCoherenteConExtension()
- respuesta()
- formatearTamaño()
- mensajeError() 

Son private porque son detalles de implementación que ayudan a los métodos públicos a hacer su trabajo.

**Método estático**
- obtenerAlias() es static porque no depende del estado de ninguna instancia en particular, solo consulta un dato de sesión. 
### Atributos privados

| Atributo | Tipo | Descripción |
|---|---|---|
| $directorio | string | Ruta absoluta al directorio /uploads |
| $tamañoMaximo | int | Límite en bytes, 3 MB por defecto |
| $mimePermitidos | array | Lista blanca de tipos MIME aceptados |
| $extensionesPermitidas | array | Lista blanca de extensiones aceptadas |

### Métodos públicos

| Método | Descripción |
|---|---|
| subir(array $archivo) | Valida y almacena el archivo de forma segura |
| listar() | Devuelve la lista de archivos con nombre, tamaño y fecha |
| eliminar(string $nombre) | Elimina con validación anti Path Traversal |
| renombrar(string $actual, string $alias) | Guarda un alias visible en sesión |
| descargar(string $nombre) | Sirve el archivo como descarga forzada |
| obtenerAlias(string $nombre) | Método estático: devuelve el alias guardado o el nombre original |

## 5. Medidas de seguridad aplicadas

| Medida | Descripción |
|---|---|
| MIME real con finfo | Detecta el tipo real del archivo, sin confiar en la cabecera HTTP que envía el navegador |
| Lista blanca de extensiones | Solo PDF, JPG y PNG son aceptados |
| Coherencia MIME y extensión | Evita archivos políglotos, como un script disfrazado de imagen |
| Límite de tamaño | Máximo 3 MB por archivo |
| Renombrado con SHA-256 y timestamp | El nombre en disco es imposible de adivinar y nunca conserva el nombre original tal cual lo subió el usuario, lo que evita ejecución directa |
| Prevención de Path Traversal | basename() más realpath() más strpos() confirman que el archivo está dentro del directorio autorizado |
| .htaccess en /uploads/ | Bloquea la ejecución de PHP y otros scripts dentro del directorio de archivos |
| Cabeceras de descarga | Content-Type: application/octet-stream y X-Content-Type-Options: nosniff fuerzan la descarga sin ejecución |
| Token CSRF | random_bytes(32) genera un token por sesión, comparado con hash_equals() en cada formulario |
| Protección contra XSS | htmlspecialchars(ENT_QUOTES) se aplica a toda variable impresa en el HTML |
| Cookies HttpOnly y SameSite | La cookie de sesión es inaccesible desde JavaScript y no se envía en peticiones de otros sitios |
| Cabeceras HTTP generales | X-Frame-Options, X-Content-Type-Options y Content-Security-Policy en cada respuesta |
| Patrón PRG | Post, Redirect, Get evita el reenvío accidental de un formulario al recargar la página |

La explicación detallada de cada medida, junto con un desglose de cuántos archivos hay de cada tipo y cuánto espacio ocupan, está disponible en seguridad.php dentro de la aplicación.

---

*Actividad B2 - Seguridad y Vulnerabilidades - UTPL 2026*
