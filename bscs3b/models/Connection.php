<?php
define("SERVER", $_ENV["SERVER01"]);
define("USER", $_ENV["DBUSER"]);
define("PWORD", $_ENV["PASSWORD"]);
define("DBASE", $_ENV["DATABASE"]);
define("CHARSET", $_ENV["CHARSET"]);

class Connection {
  static $conn = false;

  public function connect() {
    $cnString = "mysql:host=".SERVER."; dbname=".DBASE. "; charset=".CHARSET;
    $options = [
      \PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,
      \PDO::ATTR_EMULATE_PREPARES=>false,
      \PDO::ATTR_STRINGIFY_FETCHES=>false,
      \PDO::ATTR_PERSISTENT=>false
    ];

    try {
      static::$conn = new \PDO($cnString, USER, PWORD, $options);
    } catch (\PDOException $er) {
      echo "Connection Error: " .$er->getMessage();
    }
    return static::$conn;
  }

  public function closeConnection() {
    static::$conn = null;
    return null;
  }
}