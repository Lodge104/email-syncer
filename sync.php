<?php
require __DIR__ . '/vendor/autoload.php';
include 'variables.php';

// $bearer = getenv('BEARER');
// $url = getenv('URL');
// $dburl = getenv('DBURL');
// $mlapi = getenv('MLAPI');

set_time_limit(0);

date_default_timezone_set("America/New_York");

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

$json = json_decode($resp, true);

$date = date("Y.m.d");

foreach($json['members'] as $row => $data){

        $collection = (new MongoDB\Client(
          $dburl))->lodge104->members;
        
            
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
                    ['$set' => ['lastUpdated' => $date]],
                );
            }
            if ($upserted == 1) {
                $updateResult2 = $collection->updateOne(
                    ['bsaID' => $data['bsaID']],
                    ['$set' => ['lastUpdated' => $date]],
                );
            }
}

$collection = (new MongoDB\Client(
  $dburl))->lodge104->members;

$find = $collection->find(['lastUpdated' => $date]);

foreach($find as $member) {

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
    'level' => $member['obv']
  ]
];

$addedSubscriber = $groupsApi->addSubscriber($groupId, $subscriber); // returns added subscriber


$collection2 = (new MongoDB\Client(
  $dburl))->lodge104->log;

    $insert = $collection2->insertOne($addedSubscriber);

sleep(2);

}

print('All done');