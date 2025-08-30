<?php

require_once __DIR__ . '/../models/DbConnection.php';
require_once __DIR__ . '/../models/Member.php';
require_once __DIR__ . '/../services/BillService.php';
require_once __DIR__ . '/../config/linebot.php';

class MessageHandler
{
  // 處理文字訊息
  public static function handleText($userMessage, $groupId)
  {
    $liffBaseUrl = 'https://liff.line.me/2008005425-w5zrAGqk';
    $fullLiffUrl = $liffBaseUrl . '?groupId=' . urlencode($groupId);
    if ($userMessage === '功能') {
      $flexMessageJson = '{
        "type": "bubble",
        "size": "micro",
        "header": {
            "type": "box",
            "layout": "vertical",
            "contents": [
                {
                    "type": "text",
                    "text": "💵 分帳小幫手",
                    "weight": "bold",
                    "align": "center",
                    "size": "20px"
                }
            ],
            "backgroundColor": "#c8e1ef66",
            "alignItems": "center",
            "justifyContent": "center"
        },
        "body": {
            "type": "box",
            "layout": "vertical",
            "contents": [
                {
                    "type": "text",
                    "text": "所有成員\n首次使用需點擊\n⬇️",
                    "weight": "regular",
                    "style": "normal",
                    "align": "center",
                    "wrap": true,
                    "size": "sm",
                    "offsetBottom": "-8px"
                },
                {
                    "type": "button",
                    "action": {
                        "type": "postback",
                        "label": "成為分母++",
                        "data": "register_member"
                    },
                    "height": "md",
                    "style": "link",
                    "color": "#155e75"
                },
                {
                    "type": "button",
                    "action": {
                        "type": "uri",
                        "label": "新增帳單",
                        "uri": "' . $fullLiffUrl . '"
                    },
                    "height": "sm",
                    "style": "primary",
                    "color": "#06b6d4"
                },
                {
                    "type": "button",
                    "action": {
                        "type": "postback",
                        "label": "結算",
                        "data": "get_balance"
                    },
                    "style": "primary",
                    "color": "#ef4444",
                    "height": "sm",
                    "offsetTop": "5px",
                    "offsetBottom": "5px"
                }
            ],
            "backgroundColor": "#c8e1ef",
            "spacing": "none",
            "margin": "none",
            "borderWidth": "none",
            "cornerRadius": "none"
        },
        "styles": {
            "body": {
                "separator": true,
                "separatorColor": "#00000055"
            }
        }
    }';

      $flexMessageArray = json_decode($flexMessageJson, true);

      return [
        'type' => 'flex',
        'altText' => '分帳小幫手選單',
        'contents' => $flexMessageArray
      ];
    } else {
      return [
        'type' => 'text',
        'text' => '你好！我是分帳小幫手。請輸入「功能」來查看選單。'
      ];
    }
  }
  // 處理postback
  public static function handlePostback($data, $groupId, $userId)
  {
    switch ($data) {
      case 'register_member':
        try {
          $db = DbConnection::getInstance();
          $result = Member::registerMember($db, $groupId, $userId);
          $profile = self::getProfile($userId); // 取得使用者資訊
          $displayName = $profile ? $profile['displayName'] : '使用者'; 
          return [
            'type' => 'text',
            'text' => $result ? $displayName . " 加入成功！" : $displayName . " 已經加入過了。"
          ];
        } catch (Exception $e) {
          error_log('Error in register_member postback: ' . $e->getMessage());
          return [
            'type' => 'text',
            'text' => '請在群組內使用。'
          ];
        }

      case 'get_balance':
        return [
          'type' => 'text',
          'text' => '「結算」功能正在開發中，敬請期待！'
        ];

      default:
        return [
          'type' => 'text',
          'text' => '無法識別的指令。'
        ];
    }
  }
  //取得使用者
  public static function getProfile($userId)
  {
    if (!defined('LINE_CHANNEL_ACCESS_TOKEN') || empty(LINE_CHANNEL_ACCESS_TOKEN)) {
      error_log('LINE_CHANNEL_ACCESS_TOKEN is not defined or empty.');
      return null;
    }

    $url = "https://api.line.me/v2/bot/profile/" . urlencode($userId);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FAILONERROR, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
      error_log('cURL error when getting profile for ' . $userId . ': ' . curl_error($ch));
      curl_close($ch);
      return null;
    }

    curl_close($ch);

    $profile = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE || !isset($profile['displayName'])) {
      error_log('Failed to decode profile JSON or missing displayName for userId: ' . $userId);
      return null;
    }

    return $profile;
  }
  public static function sendPushMessage($groupId, $flexMessage)
  {
    require_once __DIR__ . '/../config/linebot.php';

    $replyData = [
      'to' => $groupId,
      'messages' => [$flexMessage]
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.line.me/v2/bot/message/push');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($replyData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Content-Type: application/json',
      'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
    ]);
    curl_exec($ch);
    curl_close($ch);
  }
}
