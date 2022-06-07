<?php
namespace MyId;

use Workerman\Connection\ConnectionInterface;

/**
 * LogPackN2 Protocol.
 */
class IdPackEof
{
    /**
     * Check the integrity of the package.
     *
     * @param string $buffer
     * @return int
     */
    public static function input($buffer, ConnectionInterface $connection)
    {
        // Judge whether the package length exceeds the limit.
        if (isset($connection->maxPackageSize) && \strlen($buffer) >= $connection->maxPackageSize) {
            $connection->close();
            return 0;
        }
        //  Find the position of  "\n".
        $pos = \strpos($buffer, "\n");
        // No "\n", packet length is unknown, continue to wait for the data so return 0.
        if ($pos === false) {
            return 0;
        }
        // Return the current package length.
        return $pos + 1;
    }


    /**
     * Encode.
     *
     * @param string $buffer
     * @return string
     */
    public static function encode($buffer)
    {
        if (!is_scalar($buffer)) $buffer = IdLib::toJson($buffer);
        return $buffer . "\r\n";
    }

    /**
     * Decode.
     *
     * @param string $buffer
     * @return string
     */
    public static function decode($buffer)
    {
        return rtrim($buffer, "\r\n");
        return substr($buffer, 0, -2);
    }
}