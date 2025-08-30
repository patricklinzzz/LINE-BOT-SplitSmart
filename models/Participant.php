<?php
require_once __DIR__ . '/DbConnection.php';
class Participant
{
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
}
