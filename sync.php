<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$client = new Aws\CloudWatchLogs\CloudWatchLogsClient([
  'credentials' => array(
    'key'    => $_ENV['AWSKEY'],
    'secret' => $_ENV['AWSSECRET'],
  ),
  'region' => 'us-east-1',
  'version' => '2014-03-28'
]);

$bearer = $_ENV['BEARER'];
$url = $_ENV['URL'];
$dburl = $_ENV['DBURL'];
$mlapi = $_ENV['MLAPI'];

set_time_limit(0);
ignore_user_abort(TRUE);

date_default_timezone_set("America/New_York");

try {
  $curl = curl_init($url);
  curl_setopt($curl, CURLOPT_URL, $url);
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

  $headers = array(
    "Accept: application/json",
    ("Authorization: Bearer " . $bearer),
  );
  curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
  //for debug only!
  curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
  curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

  $resp = curl_exec($curl);
  if (!curl_errno($curl)) {
    switch ($http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE)) {
      case 200:  # OK
        break;
      default:
    }
  }
  curl_close($curl);
} catch (Exception $e) {
  $error = $client->putLogEvents([
    'logEvents' => [
        [
            'message' => 'Error at CURL Event.',
            'timestamp' => round(microtime(true) * 1000),
        ],
    ],
    'logGroupName' => 'lodge104/email-sync',
    'logStreamName' => 'error',
]);
}

$json = json_decode($resp, true);
try {
  foreach ($json['members'] as $row => $data) {

    $collection = (new MongoDB\Client(
      $dburl
    ))->lodge104->members;


    $updateResult = $collection->updateOne(
      ['bsaID' => $data['bsaID']],
      ['$set' => $data],
      ['upsert' => true]
    );
    $result = $updateResult->getModifiedCount();
    $upserted = $updateResult->getUpsertedCount();
    if ($result == 1) {
      $updateResult2 = $collection->updateOne(
        ['bsaID' => $data['bsaID']],
        ['$set' => ['updated' => '1']],
      );
    }
    if ($upserted == 1) {
      $updateResult2 = $collection->updateOne(
        ['bsaID' => $data['bsaID']],
        ['$set' => ['updated' => '1']],
      );
    }
  }
} catch (Exception $e) {
  $error = $client->putLogEvents([
    'logEvents' => [
        [
            'message' => 'Error at updating mongodb with fetched member data.',
            'timestamp' => round(microtime(true) * 1000),
        ],
    ],
    'logGroupName' => 'lodge104/email-sync',
    'logStreamName' => 'error',
]);
}
try {
  $collection = (new MongoDB\Client(
    $dburl
  ))->lodge104->members;

  $find = $collection->find(['updated' => '1']);
} catch (Exception $e) {
  $error = $client->putLogEvents([
    'logEvents' => [
        [
            'message' => 'Error at finding updated members in mongodb.',
            'timestamp' => round(microtime(true) * 1000),
        ],
    ],
    'logGroupName' => 'lodge104/email-sync',
    'logStreamName' => 'error',
]);
}

foreach ($find as $member) {
  try {

    $groupsApi = (new \MailerLiteApi\MailerLite($mlapi))->groups();

    $groupId = 2629262;

    $subscriber = [
      'email' => $member['emailAddress'],
      'name' => $member['firstName'],
      'fields' => [
        'last_name' => $member['lastName'],
        'bsa_id' => $member['bsaID'],
        'dues_year' => $member['duesYear'],
        'chapter' => $member['chapter'],
        'level' => $member['obv'],
      ]
    ];

    $addedSubscriber = $groupsApi->addSubscriber($groupId, $subscriber); // returns added subscriber

    $removeUpdated = $collection->updateOne(
      ['bsaID' => $member['bsaID']],
      ['$set' => ['updated' => '0']],
    );

    $log = $client->putLogEvents([
      'logEvents' => [
          [
              'message' => implode(" ", json_decode($addedSubscriber)),
              'timestamp' => round(microtime(true) * 1000),
          ],
      ],
      'logGroupName' => 'lodge104/email-sync',
      'logStreamName' => 'updates',
  ]);

    sleep(2);
  } catch (Exception $e) {
    $error = $client->putLogEvents([
      'logEvents' => [
          [
              'message' => 'Error updating user into ML:' . $member['bsaID'] . '',
              'timestamp' => round(microtime(true) * 1000),
          ],
      ],
      'logGroupName' => 'lodge104/email-sync',
      'logStreamName' => 'error',
  ]);
  }
}

$completed = $client->putLogEvents([
  'logEvents' => [
      [
          'message' => 'Sync completed sucessfully.',
          'timestamp' => round(microtime(true) * 1000),
      ],
  ],
  'logGroupName' => 'lodge104/email-sync',
  'logStreamName' => 'successful',
]);
