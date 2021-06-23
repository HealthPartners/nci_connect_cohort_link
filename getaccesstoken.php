<?php
include_once __DIR__ . '../vendor/autoload.php';

/**
 * This function calls the Google Server to get access token based on key file configured
 */
function getAccessTokenFromKeyFile($keyFileLocation){

    $client = new Google\Client();
    $client->setApplicationName("Client_Library_Examples");
    //putenv('GOOGLE_APPLICATION_CREDENTIALS=\xampp4\nih-nci-dceg-connect-dev-hp-2e5d4a3a2660.json');
    $tempEnvVar = "GOOGLE_APPLICATION_CREDENTIALS=" . $keyFileLocation;
    putenv($tempEnvVar);
    $client->useApplicationDefaultCredentials();
    $client->setScopes(['https://www.googleapis.com/auth/userinfo.email']);
    $token = $client->fetchAccessTokenWithAssertion();

    $nciAPIAccessToken=$token["access_token"];
    return $nciAPIAccessToken;
}
