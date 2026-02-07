<?php

declare(strict_types=1);

namespace VoidLux\P2P\Protocol;

/**
 * Encodes/decodes messages with a 4-byte uint32 length prefix + JSON payload.
 *
 * Wire format: [4 bytes: payload length (big-endian uint32)][N bytes: JSON payload]
 */
class MessageCodec
{
    /**
     * Encode a message array to wire format.
     */
    public static function encode(array $message): string
    {
        $json = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $length = strlen($json);
        return pack('N', $length) . $json;
    }

    /**
     * Attempt to decode one message from a buffer.
     * Returns [message_array, bytes_consumed] or null if buffer is incomplete.
     */
    public static function decode(string $buffer): ?array
    {
        if (strlen($buffer) < 4) {
            return null;
        }

        $unpacked = unpack('Nlength', $buffer);
        $length = $unpacked['length'];

        if ($length > 1048576) { // 1MB sanity limit
            throw new \RuntimeException("Message too large: {$length} bytes");
        }

        if (strlen($buffer) < 4 + $length) {
            return null;
        }

        $json = substr($buffer, 4, $length);
        $message = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return [$message, 4 + $length];
    }

    /**
     * Decode all complete messages from a buffer.
     * Returns [messages_array, remaining_buffer].
     */
    public static function decodeAll(string $buffer): array
    {
        $messages = [];

        while (strlen($buffer) >= 4) {
            $result = self::decode($buffer);
            if ($result === null) {
                break;
            }
            [$message, $consumed] = $result;
            $messages[] = $message;
            $buffer = substr($buffer, $consumed);
        }

        return [$messages, $buffer];
    }
}
