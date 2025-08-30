<?php
require_once __DIR__ . '/../models/Bill.php';
require_once __DIR__ . '/../models/Participant.php';
require_once __DIR__ . '/MessageHandler.php';

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
}
