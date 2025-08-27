<?php

require_once __DIR__ . '/../models/DbConnection.php';
require_once __DIR__ . '/../models/Member.php';
require_once __DIR__ . '/../services/BillService.php';

class MessageHandler
{
  public static function handleText($userMessage)
  {
    //, $replyToken, $groupId, $userId
    // $replyMessage = [];
    // $replyData = null;

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
                "type": "postback",
                "label": "新增帳單",
                "data": "add_bill"
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
  public static function handlePostback($data, $groupId, $userId)
  {
    switch ($data) {
      case 'register_member':
        $db = DbConnection::getInstance();
        $result = Member::registerMember($db, $groupId, $userId);
        $profile = self::getProfile($userId);
        return [
          'type' => 'text',
          'text' => $result ? $profile['displayName'] . "加入成功！" : $profile['displayName'] . "已經加入過了。"
        ];

      case 'add_bill':
        return [];

      case 'get_balance':
        return [];

      default:
        return [
          'type' => 'text',
          'text' => '無法識別的指令。'
        ];
    }
  }
  public static function getProfile($userId)
  {
    require_once __DIR__ . '/../config/linebot.php';

    $url = "https://api.line.me/v2/bot/profile/" . urlencode($userId);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $profile = json_decode($response, true);
    return $profile;
  }
}
