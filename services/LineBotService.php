<?php

class LineBotService
{
  private $channelAccessToken;
  private $apiUrl = 'https://api.line.me/v2/bot/message/reply';

  public function __construct($channelAccessToken)
  {
    $this->channelAccessToken = $channelAccessToken;
  }

  public function replyMessage($replyToken, array $messages)
  {
    if (empty($this->channelAccessToken)) {
      error_log('LINE Channel Access Token is not set.');
      return false;
    }

    $headers = [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $this->channelAccessToken
    ];

    $data = [
      'replyToken' => $replyToken,
      'messages' => $messages
    ];

    $ch = curl_init($this->apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpcode != 200 || $error) {
      error_log("LINE API Error. HTTP Code: {$httpcode}. cURL Error: {$error}. Response: {$result}");
      return false;
    }

    return true;
  }
}
