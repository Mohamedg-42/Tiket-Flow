<?php
$url = 'https://api.test.bictorys.com/simulator/v1/confirm?transaction_id=f2dac02e-a8ed-4e3f-bc87-f3095fe7d2d1';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_HEADER, true);
$res = curl_exec($ch);
echo $res;
