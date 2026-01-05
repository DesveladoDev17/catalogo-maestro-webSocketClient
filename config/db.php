<?php
class database
{
    private static $conn;

    public static function connect_mysql()
    {
        try {
            self::$conn = new mysqli('tienda.cphrewards.com.mx', 'loyalty', 'C3&JipHoS7?SpLxejisw', 'modulo_tienda', 3306);
            self::$conn->query('SET NAMES utf8');
            return self::$conn;
        } catch (Exception $e) {
            return self::$conn = "Error: " . $e->getMessage();
        }
    }
}
