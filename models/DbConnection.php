<?php
require_once __DIR__ . '/../config/database.php';

class DbConnection
{
  private static $instance = null;
  private $conn;

  private function __construct()
  {
    $this->conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

    if ($this->conn->connect_error) {
      die("資料庫連線失敗: " . $this->conn->connect_error);
    }

    $this->conn->set_charset("utf8mb4");
  }

  public static function getInstance()
  {
    if (self::$instance == null) {
      self::$instance = new DbConnection();
    }
    return self::$instance->conn;
  }
}
