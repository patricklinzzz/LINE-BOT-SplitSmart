<?php
require_once __DIR__ . '/Participant.php';
require_once __DIR__ . '/DbConnection.php';

class Bill
{
  // 建立帳單
  public static function createBill($db, $data)
  {
    $sql = "INSERT INTO bills (group_id,total_amount,bill_name,payer_user_id,created_at) 
    VALUE (?,?,?,?,NOW())";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("sdss", $data['groupId'], $data['amount'], $data['billName'], $data['payerId']);

    if (!$stmt->execute()) {
      error_log('Failed to create bill: ' . $stmt->error);
      $stmt->close();
      return 0;
    }

    $newBillid = $stmt->insert_id;
    $stmt->close();

    //參與者存入participants資料表
    if (is_array($data['participants']) && !empty($data['participants'])) {
      foreach ($data['participants'] as $participantUserId) {
        Participant::addParticipant($db, $newBillid, $participantUserId);
      }
    }

    return $newBillid;
  }
  // 查詢帳單
  public static function getBillById($db, $billId)
  {
    $sql = "SELECT * FROM bills WHERE bill_id = ? ";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $billId);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
      $bill = $result->fetch_assoc();
    } else {
      $bill = null;
    }

    $stmt->close();
    return $bill;
  }
  // 刪除帳單
  public static function deleteBill($db, $billId, $deleterUserId)
  {
    $bill = self::getBillById($db, $billId);
    if (!$bill) {
      error_log("Attempted to delete a non-existent bill (ID: {$billId})");
      return false; 
    }

    $success = false;
    $db->begin_transaction();

    try {
      // 刪除參與者紀錄
      $stmtParticipants = $db->prepare("DELETE FROM bill_participants WHERE bill_id = ?");
      $stmtParticipants->bind_param("i", $billId);
      $stmtParticipants->execute();
      $stmtParticipants->close();

      // 刪除帳單
      $stmtBill = $db->prepare("DELETE FROM bills WHERE bill_id = ?");
      $stmtBill->bind_param("i", $billId);
      $stmtBill->execute(); 
      $success = $stmtBill->affected_rows > 0;
      $stmtBill->close();

      $db->commit();

      if ($success) {
        $deleter = MessageHandler::getProfile($deleterUserId);
        $deleterName = $deleter['displayName'];
        $message = "{$deleterName} 刪除了帳單 \"{$bill['bill_name']}\"";
        $flexMessage = [
          'type' => 'text',
          'text' => $message,
        ];
        MessageHandler::sendPushMessage($bill['group_id'],$flexMessage);
        error_log("模擬通知 -> 群組ID {$bill['group_id']}: {$message}"); // 暫時用日誌模擬
      }
    } catch (Exception $e) {
      $db->rollback();
      error_log("刪除帳單失敗 (ID: {$billId}): " . $e->getMessage());
      return false;
    }
    return $success;
  }
}
