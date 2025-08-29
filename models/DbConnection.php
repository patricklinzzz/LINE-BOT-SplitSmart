<?php
require_once __DIR__ . '/../config/database.php';

class DbConnection
{
  private static $instance = null;
  private ?mysqli $conn = null; // 類型提示，並初始化為 null

  private function __construct()
  {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
      $this->conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
      $this->conn->set_charset("utf8mb4");
    } catch (mysqli_sql_exception $e) {
      error_log("Database Connection Error: " . $e->getMessage());
      throw new Exception("無法連接到資料庫，請檢查設定或稍後再試。");
    }
  }

  public static function getInstance(): mysqli
  {
    if (self::$instance == null) {
      self::$instance = new DbConnection();
    }
    return self::$instance->conn;
  }
}
