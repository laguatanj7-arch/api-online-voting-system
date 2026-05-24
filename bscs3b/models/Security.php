<?php

class Security {
    private static function getKey() {
        // ENCRYPTION_KEY is expected to be a 64-character hex string (32 bytes when converted to binary)
        $hexKey = $_ENV['ENCRYPTION_KEY'] ?? '';
        if (!preg_match('/^[a-fA-F0-9]{64}$/', $hexKey)) {
            throw new Exception("Encryption key must be a 64-character hex string.");
        }

        $key = hex2bin($hexKey);
        if ($key === false || strlen($key) !== 32) {
            throw new Exception("Encryption key must decode to exactly 32 bytes.");
        }

        return $key;
    }

    public static function encrypt($data) {
        $key = self::getKey();
        $cipher = "aes-256-gcm";
        
        // Generate a 12-byte IV for GCM
        $ivlen = openssl_cipher_iv_length($cipher);
        $iv = openssl_random_pseudo_bytes(12); // 12 bytes recommended for GCM
        
        $tag = "";
        
        // Encrypt the data
        $ciphertext = openssl_encrypt($data, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new Exception("Encryption failed.");
        }
        
        return [
            'ciphertext' => base64_encode($ciphertext),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag)
        ];
    }

    public static function decrypt($ciphertext, $iv, $tag) {
        $key = self::getKey();
        $cipher = "aes-256-gcm";
        
        // Decrypt the data
        $decryptedData = openssl_decrypt(
            base64_decode($ciphertext), 
            $cipher, 
            $key, 
            OPENSSL_RAW_DATA, 
            base64_decode($iv), 
            base64_decode($tag)
        );
        
        if ($decryptedData === false) {
            throw new Exception("Decryption failed. Data might be corrupted or tampered with.");
        }
        
        return $decryptedData;
    }
}
