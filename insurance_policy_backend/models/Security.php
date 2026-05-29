<?php

class Security {
  private static function getKey() {
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

  public static function encrypt($plainText) {
    $iv = random_bytes(12);
    $tag = "";
    $ciphertext = openssl_encrypt($plainText, "aes-256-gcm", self::getKey(), OPENSSL_RAW_DATA, $iv, $tag);

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
    $plainText = openssl_decrypt(
      base64_decode($ciphertext),
      "aes-256-gcm",
      self::getKey(),
      OPENSSL_RAW_DATA,
      base64_decode($iv),
      base64_decode($tag)
    );

    if ($plainText === false) {
      throw new Exception("Decryption failed.");
    }

    return $plainText;
  }

  public static function encryptJson(array $payload) {
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
      throw new Exception("Unable to encode JSON payload.");
    }

    return self::encrypt($json);
  }

  public static function decryptJson($ciphertext, $iv, $tag) {
    $json = self::decrypt($ciphertext, $iv, $tag);
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
      throw new Exception("Encrypted payload is not valid JSON.");
    }

    return $payload;
  }

  public static function createJwt(array $payload) {
    $header = ['typ' => 'JWT', 'alg' => 'HS256'];
    $encryptedClaims = self::encryptJson($payload);
    $payload = [
      'claims' => [
        'ciphertext' => $encryptedClaims['ciphertext'],
        'iv' => $encryptedClaims['iv'],
        'tag' => $encryptedClaims['tag']
      ],
      'iat' => time(),
      'exp' => time() + (60 * 60 * 24)
    ];

    $base64Header = self::base64UrlEncode(json_encode($header));
    $base64Payload = self::base64UrlEncode(json_encode($payload));
    $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, $_ENV['JWT_SECRET'], true);

    return $base64Header . "." . $base64Payload . "." . self::base64UrlEncode($signature);
  }

  public static function verifyJwt($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
      throw new Exception("Invalid token format.");
    }

    [$base64Header, $base64Payload, $base64Signature] = $parts;
    $expectedSignature = self::base64UrlEncode(hash_hmac('sha256', $base64Header . "." . $base64Payload, $_ENV['JWT_SECRET'], true));
    if (!hash_equals($expectedSignature, $base64Signature)) {
      throw new Exception("Invalid token signature.");
    }

    $tokenPayload = json_decode(self::base64UrlDecode($base64Payload), true);
    if (!is_array($tokenPayload) || !isset($tokenPayload['exp']) || $tokenPayload['exp'] < time()) {
      throw new Exception("Token expired.");
    }

    if (isset($tokenPayload['claims']['ciphertext'], $tokenPayload['claims']['iv'], $tokenPayload['claims']['tag'])) {
      $claims = self::decryptJson(
        $tokenPayload['claims']['ciphertext'],
        $tokenPayload['claims']['iv'],
        $tokenPayload['claims']['tag']
      );
      $claims['iat'] = $tokenPayload['iat'] ?? null;
      $claims['exp'] = $tokenPayload['exp'];
      return $claims;
    }

    return $tokenPayload;
  }

  private static function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
  }

  private static function base64UrlDecode($data) {
    $data = strtr($data, '-_', '+/');
    $data .= str_repeat('=', (4 - strlen($data) % 4) % 4);
    $decoded = base64_decode($data, true);
    if ($decoded === false) {
      throw new Exception("Invalid base64 data.");
    }

    return $decoded;
  }
}
