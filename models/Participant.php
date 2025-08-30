<?php
require_once __DIR__ . '/DbConnection.php';
class Participant
{
  // 將參與者填入資料庫
  public static function addParticipant($db, $billId, $userId)
  {
    $sql = "INSERT INTO bill_participants (bill_id,user_id) VALUE (?,?)";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("is", $billId, $userId);

    if (!$stmt->execute()) {
      error_log('Failed to add participant ' . $userId . ' for bill ' . $billId . ': ' . $stmt->error);
      $stmt->close();
      return false;
    }

    $stmt->close();
    return true;
  }
  // 取得參與分帳的成員清單
  public static function getParticipantsByBillId($db, $billId)
  {
    $sql = "SELECT user_id FROM bill_participants WHERE bill_id = ?";

    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $billId);
    $stmt->execute();

    $result = $stmt->get_result();

    $participants = [];
    while ($row = $result->fetch_assoc()) {
      $participants[] = $row;
    }

    $stmt->close();
    return $participants;
  }
}
