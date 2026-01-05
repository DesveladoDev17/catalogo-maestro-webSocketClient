<?php

require __DIR__ . '/vendor/autoload.php';

use WebSocket\Client;

/**
 * =======================================
 * CONFIGURACIÓN
 * =======================================
 */
const WS_URL     = 'ws://127.0.0.1:6001';
const PROGRAMA   = 'CPH Rewards';
const CLIENTE_ID = 'PHP_CLIENT_01';

const DB_HOST = 'tienda.cphrewards.com.mx';
const DB_USER = 'loyalty';
const DB_PASS = 'C3&JipHoS7?SpLxejisw';
const DB_NAME = 'modulo_tienda_demo';

/**
 * =======================================
 * CONEXIÓN BD
 * =======================================
 */
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die("❌ Error MySQL: {$conn->connect_error}");
}

/**
 * =======================================
 * WEBSOCKET CLIENT
 * =======================================
 */
function iniciarCliente(mysqli $conn): void
{
    while (true) {
        try {
            echo "\n🔌 Conectando a WebSocket...\n";

            $client = new Client(WS_URL);

            identificarCliente($client);

            while (true) {
                procesarMensaje($client->receive(), $conn);
            }

        } catch (Throwable $e) {
            echo "🔴 Error: {$e->getMessage()}\n";
            sleep(2);
        }
    }
}

/**
 * =======================================
 * IDENTIFICACIÓN
 * =======================================
 */
function identificarCliente(Client $client): void
{
    $payload = [
        'tipo'      => 'identificacion',
        'programa'  => PROGRAMA,
        'clienteId'=> CLIENTE_ID,
    ];

    $client->send(json_encode($payload));
    echo "🟢 Cliente identificado\n";
}

/**
 * =======================================
 * PROCESAR MENSAJES
 * =======================================
 */
function procesarMensaje(string $mensaje, mysqli $conn): void
{
    $data = json_decode($mensaje, true);

    if (empty($data['operation'])) {
        return;
    }

    match ($data['operation']) {
        'update'          => actualizarProductos($data, $conn),
        'update_imagenes' => actualizarImagenes($data, $conn),
        'new_propuesta'   => nuevaPropuesta($data, $conn),
        default           => print_r($data)
    };
}

/**
 * =======================================
 * UPDATE PRODUCTOS
 * =======================================
 */
function actualizarProductos(array $data, mysqli $conn): void
{
    echo "🔔 Update productos\n";


    foreach ($data['payload'][0]['items'] as $info) {
        $sku = $info['sku'];

        $producto = obtenerProducto($sku, $conn);
        if (!$producto) {
            continue;
        }

        $categoriaId = obtenerCategoriaId($info['nombre_categoria'], $conn);

        $descripcion_larga = $info['descripcion_larg'];

        actualizarProducto(
            $sku,
            $categoriaId,
            $info['nombre_producto'],
            $descripcion_larga,
            $conn
        );

        insertarImagenes($producto['cproducto_id'], $info['imagenes'], $conn);
    }
}

/**
 * =======================================
 * UPDATE IMÁGENES
 * =======================================
 */
function actualizarImagenes(array $data, mysqli $conn): void
{
    echo "🖼️ Actualizando imágenes\n";

    foreach ($data['payload'][0]['items'] as $info) {
        $producto = obtenerProducto($info['sku'], $conn);
        if (!$producto) {
            continue;
        }

        insertarImagenes($producto['cproducto_id'], $info['imagenes'], $conn);
    }
}

/**
 * =======================================
 * NUEVA PROPUESTA
 * =======================================
 */
function nuevaPropuesta(array $data, mysqli $conn): void
{
    echo "📦 Nueva propuesta\n";

    foreach ($data['payload'][0]['items'] as $producto) {
        $sku         = $producto['sku'];

        $categoriaId= obtenerCategoriaId($producto['nombre_categoria'], $conn);

        $certificado = $categoriaId === 3 ? 1 : 0;
        $tipo        = $categoriaId === 3 ? 1 : 2;

        if (obtenerProducto($sku, $conn)) {
            actualizarPuntos($sku, $producto['valor_puntos'], $tipo, $certificado, $conn);
            
            continue;
        }

        insertarProducto($producto, $categoriaId, $tipo, $certificado, $conn);
    }
}

/**
 * =======================================
 * HELPERS
 * =======================================
 */
function obtenerProducto(string $sku, mysqli $conn): ?array
{
    $sql = "SELECT cproducto_id FROM cproducto WHERE cproducto_sku = '{$sku}'";
    $res = $conn->query($sql);

    return $res && $res->num_rows ? $res->fetch_assoc() : null;
}

function obtenerCategoriaId(string $nombre, mysqli $conn): int
{
    $nombre = strtoupper($nombre);

    $sql = "SELECT ccategoria_id FROM ccategoria WHERE ccategoria_descripcion = '{$nombre}'";
    $res = $conn->query($sql);

    return ($res && $res->num_rows) ? (int)$res->fetch_assoc()['ccategoria_id'] : 0;
}

function actualizarProducto(
    string $sku,
    int $categoriaId,
    string $nombre,
    string $descripcion,
    mysqli $conn
): void {
    $sql = "
        UPDATE cproducto SET
            cproducto_idcategoria = ?,
            cproducto_nombre = ?,
            cproducto_descripcion = ?
        WHERE cproducto_sku = ?
    ";

    $stmt = $conn->prepare($sql);


    if (!$stmt) {
        die("❌ Error prepare: " . $conn->error);
    }

    $stmt->bind_param(
        "isss",
        $categoriaId,
        $nombre,
        $descripcion,
        $sku
    );

    $stmt->execute();

    if ($stmt->affected_rows === 0) {
        echo "⚠️ No se actualizó ningún registro (¿SKU existe?)";
    }

    $stmt->close();
}

function actualizarPuntos(
    string $sku,
    int $puntos,
    int $tipo,
    int $certificado,
    mysqli $conn
): void {
    $sql = "
        UPDATE cproducto SET
            cproducto_puntos = ?,
            cproducto_tipo = ?,
            cproducto_envdig = ?
        WHERE cproducto_sku = ?        
    ";

    $stmt = $conn->prepare($sql);

     $stmt->bind_param(
        "iiis",
        $puntos,
        $tipo,
        $certificado,
        $sku
    );

    $stmt->execute();

    if ($stmt->affected_rows === 0) {
        echo "⚠️ No se actualizó ningún registro (¿SKU existe?)";
    }

    $stmt->close();
}

function insertarImagenes(int $productoId, array $imagenes, mysqli $conn): void
{
    foreach ($imagenes as $imagen) {
        $imagen = $conn->real_escape_string($imagen);

        $sql = "
            INSERT INTO cimagenproducto (
                cimagenproducto_idproducto,
                cimagenproducto_nombre,
                cimagenproducto_portada
            ) VALUES (
                {$productoId},
                '{$imagen}',
                1
            )
        ";

        $conn->query($sql);
    }
}

function insertarProducto(array $p, int $categoriaId, int $tipo, int $certificado, mysqli $conn): void
{
    $sql = "
        INSERT INTO cproducto (
            cproducto_idcategoria,
            cproducto_sku,
            cproducto_nombre,
            cproducto_descripcion,
            cproducto_desc_corta,
            cproducto_puntos,
            cproducto_stock,
            cproducto_tipo,
            cproducto_tipotalla,
            cproducto_envdig
        ) VALUES (
            {$categoriaId},
            '{$p['sku']}',
            '{$p['nombre_producto']}',
            '{$p['descripcion_larg']}',
            '1',
            '{$p['valor_puntos']}',
            200,
            {$tipo},
            3,
            {$certificado}
        )
    ";

    $conn->query($sql);
}

/**
 * =======================================
 * START
 * =======================================
 */
iniciarCliente($conn);



