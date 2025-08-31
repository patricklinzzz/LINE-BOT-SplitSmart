<?php
require_once __DIR__ . '/../models/Bill.php';
require_once __DIR__ . '/../models/Participant.php';
require_once __DIR__ . '/MessageHandler.php';
require_once __DIR__ . '/../models/DbConnection.php';

class BillService
{
  public static function createBillSummaryFlexMessage($db, $billId)
  {
    $bill = Bill::getBillById($db, $billId);
    if (!$bill) {
      return null;
    }

    // 取得付款人的名稱
    $payerProfile = MessageHandler::getProfile($bill['payer_user_id']);
    $payerName = $payerProfile['displayName'] ?? '未知用戶';

    // 取得參與分帳的成員清單
    $participants = Participant::getParticipantsByBillId($db, $billId);
    $participantNames = [];
    foreach ($participants as $participant) {
      $profile = MessageHandler::getProfile($participant['user_id']);
      if ($profile) {
        $participantNames[] = $profile['displayName'];
      }
    }
    $participantsList = implode(', ', $participantNames);

    $flexMessage = [
      'type' => 'bubble',
      'body' => [
        'type' => 'box',
        'layout' => 'vertical',
        'contents' => [
          [
            'type' => 'text',
            'text' => $bill['bill_name'],
            'weight' => 'bold',
            'size' => 'xl'
          ],
          [
            'type' => 'separator',
            'margin' => 'md'
          ],
          [
            'type' => 'box',
            'layout' => 'vertical',
            'margin' => 'md',
            'contents' => [
              ['type' => 'text', 'text' => '總金額: $' . $bill['total_amount']],
              ['type' => 'text', 'text' => '付款人: ' . $payerName],
              ['type' => 'text', 'text' => '參與者: ' . $participantsList]
            ]
          ]
        ]
      ]
    ];

    return [
      'type' => 'flex',
      'altText' => '新增帳單通知',
      'contents' => $flexMessage
    ];
  }
  // 結算
  public static function getFinalBalance($db, $groupId)
  {
    $balance = []; // 儲存每個人應收或應付的金額

    $sql = "SELECT b.bill_id, b.total_amount, b.payer_user_id, p.user_id AS participant_user_id
                FROM bills b
                JOIN bill_participants p ON b.bill_id = p.bill_id
                WHERE b.group_id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("s", $groupId);
    $stmt->execute();
    $result = $stmt->get_result();
    //遍歷每一筆紀錄
    $processedBills = [];
    while ($row = $result->fetch_assoc()) {
      $billId = $row['bill_id'];
      $totalAmount = (float)$row['total_amount']; //單筆總金額
      $payerId = $row['payer_user_id']; //付款人
      $participantId = $row['participant_user_id']; //參與者

      // 確保每個使用者的餘額都已初始化為 0
      if (!isset($balances[$payerId])) {
        $balances[$payerId] = 0.0;
      }
      if (!isset($balances[$participantId])) {
        $balances[$participantId] = 0.0;
      }

      if (!isset($processedBills[$billId])) {
        // 計算每人應付金額
        $numParticipants = self::countParticipants($db, $billId);
        $owedPerPerson = $totalAmount / $numParticipants;

        // 付款人的餘額增加總金額
        $balances[$payerId] += $totalAmount;

        // 儲存這筆帳單的計算結果，避免重複計算
        $processedBills[$billId] = ['owedPerPerson' => $owedPerPerson];
      }

      // 參與者的餘額扣除應付金額
      $owedPerPerson = $processedBills[$billId]['owedPerPerson'];
      $balances[$participantId] -= $owedPerPerson;
    }
    $stmt->close();

    return $balances;
  }
  //計算參與者人數
  public static function countParticipants($db, $billId)
  {
    $sql = "SELECT COUNT(*) AS count FROM bill_participants WHERE bill_id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $billId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
      return (int)$row['count'];
    }

    return 0;
  }
  //createBalanceReportFlexMessage
  public static function createBalanceReportFlexMessage($report)
  {
    $contents = [];
    $hasBalance = false;
    foreach ($report as $userId => $balance) {
      // 忽略餘額為零的使用者，避免訊息冗長
      if (abs($balance) > 0.01) {
        $hasBalance = true;
        $profile = MessageHandler::getProfile($userId);
        $userName = $profile['displayName'] ?? '未知用戶';
        $color = $balance > 0 ? '#1DB446' : '#EF4444';
        $sign = $balance > 0 ? '應收' : '應付';
        $amount = abs($balance);

        $contents[] = [
          'type' => 'box',
          'layout' => 'horizontal',
          'contents' => [
            ['type' => 'text', 'text' => $userName, 'flex' => 2],
            ['type' => 'text', 'text' => $sign, 'align' => 'end', 'flex' => 1],
            ['type' => 'text', 'text' => '$' . number_format($amount, 2), 'align' => 'end', 'color' => $color, 'weight' => 'bold', 'flex' => 2]
          ]
        ];
        $contents[] = ['type' => 'separator', 'margin' => 'sm'];
      }
    }

    // 如果有餘額，移除最後一條分隔線
    if ($hasBalance) {
      array_pop($contents);
    } else {
      $contents[] = ['type' => 'text', 'text' => '目前沒有待結算的帳單。', 'align' => 'center'];
    }

    $flexMessage = [
      'type' => 'bubble',
      'body' => [
        'type' => 'box',
        'layout' => 'vertical',
        'contents' => array_merge(
          [
            ['type' => 'text', 'text' => '💰 結算報告', 'weight' => 'bold', 'size' => 'xl'],
            ['type' => 'separator', 'margin' => 'md']
          ],
          $contents
        )
      ]
    ];

    return [
      'type' => 'flex',
      'altText' => '結算報告',
      'contents' => $flexMessage
    ];
  }
}
