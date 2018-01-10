<?php

namespace App\Http\Controllers\Api;

use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\Laravel\AwsFacade;
use LINE\LINEBot;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use LINE\LINEBot\Constant\HTTPHeader;
use LINE\LINEBot\Event\MessageEvent;
use LINE\LINEBot\Event\MessageEvent\TextMessage;
use LINE\LINEBot\Exception\InvalidEventRequestException;
use LINE\LINEBot\Exception\InvalidSignatureException;
use LINE\LINEBot\Exception\UnknownEventTypeException;
use LINE\LINEBot\Exception\UnknownMessageTypeException;
use App\Http\Controllers\Controller;

class CallbackController extends Controller
{
    public static $config = array(
        "region" => "ap-northeast-1",
        "version" => "latest",
        "endpoint" => "http://dynamodb.ap-northeast-1.amazonaws.com/", // http://dynamodb.[リージョン名].amazonaws.com
    );

    /**
     * @param Request $req
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $req){
        $secret = env('LINE_CHANNEL_SECRET');
        $token = env('LINE_CHANNEL_ACCESS_TOKEN');
        $bot = new LINEBot(new CurlHTTPClient($token), ['channelSecret' => $secret]);
        $signature = $req->header(HTTPHeader::LINE_SIGNATURE);
        if (empty($signature)) {
            return response()->json(['message' => 'Bad Request'],400);
        }
        try {
            $events = $bot->parseEventRequest($req->getContent(), $signature);
        } catch (InvalidSignatureException $e) {
            return response()->json(['message' => 'Invalid signature'],400);
        } catch (UnknownEventTypeException $e) {
            return response()->json(['message' => 'Unknown event type has come'],400);
        } catch (UnknownMessageTypeException $e) {
            return response()->json(['message' => 'Unknown message type has come'],400);
        } catch (InvalidEventRequestException $e) {
            return response()->json(['message' => 'Invalid event request'],400);
        }
        foreach ($events as $event) {
            // USER info
            $user_id = $event->getUserId();
            $profileData = $bot->getProfile($user_id);
            if ($profileData->isSucceeded()) {
                $profile = $profileData->getJSONDecodedBody();
            }
            if ($event->getType() == 'beacon') {
                $dynamo = AwsFacade::createClient('DynamoDb');

                // got beaconevent
                if ($event->getBeaconEventType() == 'enter') {
                    $replyText = '入室しました';
                    try{
                        $dynamo->putItem(array(
                            "TableName" => 'occupy',
                            "Item" => array(
                                "LineID" => array('S' => $user_id),
                                "ScreenName" => array('S' => $profile['displayName']),
                            ),
                        ));
                    }
                    catch(DynamoDbException $e) {
                        return response()->json(['msg'=>$e->getAwsErrorMessage()], 500);
                    }
                } else if ($event->getBeaconEventType() == 'leave') {
                    $replyText = '退出しました';
                    try {
                        $dynamo->deleteItem(array(
                            "TableName" => 'occupy',
                            "Key" => array(
                                "LineID" => array('S' => $user_id),
                                "ScreenName" => array('S' => $profile['displayName']),
                            ),
                        ));
                    } catch(DynamoDbException $e) {
                        return response()->json(['msg'=>$e->getAwsErrorMessage()], 500);
                    }
                }

                $resp = $bot->replyText($event->getReplyToken(), $replyText);
            } else {
                if (!($event instanceof MessageEvent)) {
                    Log::info('Non message event has come');
                    continue;
                }
                if (!($event instanceof TextMessage)) {
                    Log::info('Non text message has come');
                    continue;
                }

                $replyText = $event->getText();
                Log::info('Reply text: ' . $replyText);
                $resp = $bot->replyText($event->getReplyToken(), $replyText);
                Log::info($resp->getHTTPStatus() . ': ' . $resp->getRawBody());
            }
        }
        return response()->json([], 200);
    }
}