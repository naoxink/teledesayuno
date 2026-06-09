<?php





define('MY_ALIAS', 'PHP_Dev');




// ==========================================================================
// 1. CONFIGURACIÓN, PARÁMETROS Y ESTILOS ANSI
// ==========================================================================
if ($argc < 2) {
    echo "\33[1;31m[ERROR]\33[0m Falta el parámetro de la sala.\nUso: php teledesayuno_cli.php <canal>\n";
    exit(1);
}

// Cambiamos la terminal a modo cbreak (lectura instantánea de teclas) y sin eco automático
shell_exec('stty cbreak -echo');

// Al salir, restauramos la terminal a su estado normal (sane) para no romper la consola
register_shutdown_function(function() {
    shell_exec('stty sane');
});

define('ABLY_KEY', 'HYoAwg.Z1gnlA:nNwRcynU1A-55wN9Wt7jnNBAGft-Izu1ONLs2RNzE54');
$sala = trim($argv[1]);
define('CHANNEL_NAME', 'sala-' . $sala);

// Paleta de colores ANSI profesionales
define('CLR_RESET',   "\33[0m");
define('CLR_TU',      "\33[1;32m"); // Verde brillante (Tú)
define('CLR_OTRO',    "\33[1;35m"); // Magenta (Otros usuarios)
define('CLR_TIME',    "\33[90m");   // Gris oscuro (Timestamps)
define('CLR_ERROR',   "\33[1;31m"); // Rojo (Errores)
define('CLR_SYSTEM',  "\33[1;36m"); // Cian (Información del sistema)
define('CLR_PROMPT',  "\33[1;33m"); // Amarillo (El cursor > )

// Paleta de colores dinámicos para el resto de usuarios
define('PALETA_COLORES', [
    "\33[1;35m", // Magenta
    "\33[1;34m", // Azul brillante
    "\33[1;36m", // Cian brillante
    "\33[1;33m", // Amarillo/Marrón brillante
    "\33[35m",   // Magenta oscuro
    "\33[34m",   // Azul oscuro
    "\33[36m",   // Cian oscuro
    "\33[1;31m"  // Rojo brillante (por si quieres dar emoción)
]);

$mensajesSinLeer = 0;
$bufferMensaje = "";
$ultimoEvento = "";
$tituloOriginal = "🔐 P2P Encrypted - #" . $sala;

// Establecemos el título inicial de la pestaña de la terminal
echo "\033]0;" . $tituloOriginal . "\007";

// Función para asignar un color fijo y consistente según el nombre de usuario
function obtenerColorUsuario($nombre) {
    // crc32 convierte el string en un número entero único basado en sus caracteres
    $hash = abs(crc32($nombre));
    // Escogemos un índice del array usando el módulo según el tamaño de la paleta
    $indice = $hash % count(PALETA_COLORES);
    return PALETA_COLORES[$indice];
}

// --- SOLICITUD SEGURA DE CONTRASEÑA ---
echo "\33[1;33mContraseña para la sala [" . $sala . "]: \33[0m";

$passwordCorta = "";
while (true) {
    $char = fgetc(STDIN);
    if ($char !== false) {
        $ascii = ord($char);

        if ($char === "\n" || $char === "\r") {
            echo "\n"; // Saltamos de línea al terminar
            break;
        } elseif ($ascii === 127 || $ascii === 8) { // Borrar
            if (strlen($passwordCorta) > 0) {
                $passwordCorta = substr($passwordCorta, 0, -1);
                echo "\b \b"; // Borramos visualmente el asterisco
            }
        } else {
            if ($ascii >= 32) {
                $passwordCorta .= $char;
                echo "*"; // Pintamos un asterisco en vez de la letra real
            }
        }
    }
    usleep(10000);
}

if (trim($passwordCorta) === "") {
    echo "\33[1;31m[ERROR]\33[0m La contraseña no puede estar vacía.\n";
    exit(1);
}

// Derivación de clave PBKDF2
$saltPHP = "salt-" . $sala;
$claveDerivada = hash_pbkdf2("sha256", trim($passwordCorta), $saltPHP, 100000, 32, true);
define('ENCRYPTION_KEY', $claveDerivada);
define('AUTH_BASIC_TOKEN', 'Authorization: Basic ' . base64_encode(trim(ABLY_KEY)));

// Cabecera limpia de la aplicación
echo "\r\33[2K"; // Limpiar línea por si acaso
echo CLR_SYSTEM . "===================================================================\n";
echo "  P2P ENCRYPTED TERMINAL CHAT v1.0\n";
echo "===================================================================\n" . CLR_RESET;
echo CLR_TIME . "[" . date('H:i:s') . "] " . CLR_SYSTEM . "[INFO]" . CLR_RESET . " Canal activo: " . $sala . "\n";

// Apertura del Stream (SSE)
stream_set_blocking(STDIN, false);
$sseUrl = "https://realtime.ably.io/event-stream?channels=" . urlencode(CHANNEL_NAME) . "&key=" . urlencode(ABLY_KEY) . "&v=1.2";
$contextoSse = stream_context_create(['http' => ['user_agent' => 'PHP_Terminal_Chat', 'header' => "Accept: text/event-stream\r\n"]]);
$ablyStream = @fopen($sseUrl, "r", false, $contextoSse);

if (!$ablyStream) {
    $error = error_get_last();
    die(CLR_ERROR . "[ERROR CRÍTICO SSE] No se pudo conectar: " . ($error['message'] ?? 'Error desconocido') . "\n" . CLR_RESET);
}

stream_set_blocking($ablyStream, false);
echo CLR_TIME . "[" . date('H:i:s') . "] " . CLR_SYSTEM . "[OK]" . CLR_RESET . " Conexión segura establecida de extremo a extremo.\n";
echo CLR_TIME . "-------------------------------------------------------------------\n" . CLR_RESET;
echo CLR_PROMPT . "> " . CLR_RESET;

$bufferMensaje = "";
$ultimoEvento = "";

// Informar de la conexión
mandarMensaje('¡' . MY_ALIAS . ' se ha conectado! ¡Cuidao!');

// Capturamos el Ctrl + C (SIGINT)
pcntl_async_signals(true);
pcntl_signal(SIGINT, function() {
    // 1. Enviamos el mensaje
    mandarMensaje("¡" . MY_ALIAS . " se ha desconectado!");

    // 2. Restauramos la terminal
    shell_exec('stty sane');

    // 3. Salimos formalmente
    exit(0);
});

// ==========================================================================
// 2. BUCLE PRINCIPAL (Asíncrono)
// ==========================================================================
while (true) {

    if (feof($ablyStream)) {
        die("\n" . CLR_ERROR . "[ALERT] Conexión cerrada por el servidor remoto.\n" . CLR_RESET);
    }

    // --- LEER DATOS ENTRANTES DE ABLY (SSE) ---
    if ($linea = fgets($ablyStream)) {
        $lineaClean = trim($linea);

        if (!empty($lineaClean)) {
            if (strpos($lineaClean, 'event: ') === 0) {
                $ultimoEvento = substr($lineaClean, 7);
            }

            if (strpos($lineaClean, 'data: ') === 0) {
                $payloadRaw = substr($lineaClean, 6);

                if ($ultimoEvento === 'error') {
                    echo "\r\33[2K" . CLR_ERROR . "[ERROR STREAM]: " . $payloadRaw . "\n" . CLR_RESET . CLR_PROMPT . "> " . CLR_RESET . $bufferMensaje;
                    continue;
                }

                $msgJson = json_decode($payloadRaw, true);
                if ($msgJson) {
                    $mensajes = isset($msgJson[0]) ? $msgJson : [$msgJson];
                    foreach ($mensajes as $msgData) {
                        $clientId = $msgData['clientId'] ?? 'Anónimo';

                        if ($clientId !== MY_ALIAS) {
                            if (!isset($msgData['data'])) {
                                continue;
                            }

                            $dataContenido = $msgData['data'];
                            if (is_string($dataContenido)) {
                                $payload = json_decode($dataContenido, true);
                            } else {
                                $payload = $dataContenido;
                            }

                            if (!is_array($payload) || !isset($payload['iv']) || !isset($payload['msg'])) {
                                continue;
                            }

                            $iv = base64_decode($payload['iv']);
                            $rawMsg = base64_decode($payload['msg']);

                            $ciphertext = substr($rawMsg, 0, -16);
                            $tag = substr($rawMsg, -16);

                            $decrypted = openssl_decrypt($ciphertext, 'aes-256-gcm', ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv, $tag);

                                // --- INTERFAZ: RENDERIZADO DE MENSAJE ENTRANTE ---
                                echo "\r\33[2K"; // Limpiamos la línea del prompt por si acaso

                                $timestamp = CLR_TIME . "[" . date('H:i:s') . "] " . CLR_RESET;

                                if ($decrypted === false) {
                                    echo $timestamp . CLR_ERROR . "☠️ [" . $clientId . "]: Fallo de integridad.\n" . CLR_RESET;
                                } else {
                                    $msgContent = json_decode($decrypted, true);
                                    $autor = $msgContent['autor'] ?? $clientId;
                                    $texto = $msgContent['texto'] ?? '';

                                    $colorAutor = obtenerColorUsuario($autor);

                                    echo $timestamp . $colorAutor . "[" . $autor . "]" . CLR_RESET . " " . trim($texto) . "\n";

                                    // Incrementamos los mensajes sin leer para el título de la pestaña
                                    $mensajesSinLeer++;

                                    // Actualizamos el título de la pestaña de la terminal de forma dinámica
                                    // \033]0; -> Indica inicio de cambio de título
                                    // \007    -> Indica fin del comando
                                    echo "\033]0;({$mensajesSinLeer}) 💬 Nuevos mensajes | {$tituloOriginal}\007";

                                    // Mandamos también un leve destello/sonido por si el OS lo apoya
                                    echo "\a";
                                }

                                // Volvemos a pintar el prompt y lo que lleves escrito (que ahora controlas tú en el buffer)
                                echo CLR_PROMPT . "> " . CLR_RESET . $bufferMensaje;
                        }
                    }
                }
            }
        }
    }

// --- LEER TECLADO LOCAL (CON REDIBUJADO ROBUSTO) ---
    $char = fgetc(STDIN);
    if ($char !== false) {

        // En cuanto el usuario toca CUALQUIER tecla, asumimos que ya está mirando la terminal
        if ($mensajesSinLeer > 0) {
            $mensajesSinLeer = 0;
            // Restauramos el título original sin el contador
            echo "\033]0;" . $tituloOriginal . "\007";
        }

        // Convertimos el carácter a su valor numérico ASCII para evitar fallos de codificación
        $ascii = ord($char);

        if ($char === "\n" || $char === "\r") { // El usuario pulsa Enter
            $msgPlano = trim($bufferMensaje);
            if ($msgPlano !== "") {

                $httpCode = mandarMensaje($msgPlano);

                renderizarMensajePropio($httpCode, $msgPlano);
            } else {
                echo "\r\33[2K";
            }
            $bufferMensaje = "";
            echo CLR_PROMPT . "> " . CLR_RESET;

        } elseif ($ascii === 127 || $ascii === 8) {
            // Captura estándar de Backspace (ASCII 127 o 8)
            if (strlen($bufferMensaje) > 0) {
                // Quitamos el último carácter del búfer interno
                $bufferMensaje = substr($bufferMensaje, 0, -1);

                // EL TRUCO: En vez de mover el cursor con \b, borramos la línea entera
                // con comandos ANSI (\r\33[2K) y volvemos a pintar el prompt con el búfer actualizado.
                echo "\r\33[2K" . CLR_PROMPT . "> " . CLR_RESET . $bufferMensaje;
            }
        } else {
            // Cualquier otro carácter imprimible se añade y se muestra
            // Evitamos meter caracteres de control huérfanos filtrando el ASCII
            if ($ascii >= 32) {
                $bufferMensaje .= $char;
                echo $char;
            }
        }
    }

    usleep(20000);
}

function mandarMensaje($texto) {
    $estructuraChat = json_encode(["autor" => MY_ALIAS, "texto" => $texto]);

    $iv = openssl_random_pseudo_bytes(12);
    $tag = "";
    $ciphertext = openssl_encrypt($estructuraChat, 'aes-256-gcm', ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv, $tag);

    $payloadCifrado = [
        "iv" => base64_encode($iv),
        "msg" => base64_encode($ciphertext . $tag)
    ];

    $urlPost = "https://rest.ably.io/channels/" . urlencode(CHANNEL_NAME) . "/messages";
    $postData = json_encode([
        'name' => 'msg',
        'clientId' => MY_ALIAS,
        'data' => $payloadCifrado
    ]);

    $ch = curl_init($urlPost);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", AUTH_BASIC_TOKEN, "User-Agent: PHP_Terminal_Chat"]);

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $httpCode;
}

function renderizarMensajePropio($httpCode, $texto) {
    echo "\r\33[2K"; // Limpiamos la línea de edición
    $timestamp = CLR_TIME . "[" . date('H:i:s') . "] " . CLR_RESET;

    if ($httpCode === 201) {
        echo $timestamp . CLR_TU . "[" . MY_ALIAS . "]" . CLR_RESET . " " . $texto . "\n";
    } else {
        echo $timestamp . CLR_ERROR . "[Error de envío HTTP " . $httpCode . "]\n" . CLR_RESET;
    }
}