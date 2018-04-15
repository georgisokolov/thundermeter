<?php
require __DIR__ . '/vendor/autoload.php';

define( 'APPLICATION_NAME', 'ThunderMeter' );
define( 'CREDENTIALS_PATH', '~/.credentials/ThunderMeter.json' );
define( 'CLIENT_SECRET_PATH', __DIR__ . '/client_secret.json' );

define( 'SCOPES', implode( ' ', array( Google_Service_Gmail::GMAIL_SEND, Google_Service_Gmail::GMAIL_MODIFY, Google_Service_Gmail::GMAIL_READONLY ) ) );

/**
 * Get a new authorized API client.
 */
function getClient( $email ) {

    $client = new Google_Client( /*$google_config*/ );
    $client->setApplicationName( APPLICATION_NAME );
    $client->setScopes( SCOPES );
    $client->setAuthConfigFile( CLIENT_SECRET_PATH );
    $client->setAccessType( "offline" );

    // HACK TO GET IT WORKING ON FREAKING WINDOWS
    $guzzleClient = new \GuzzleHttp\Client(array( 'curl' => array( CURLOPT_SSL_VERIFYPEER => false, ), ));
    $client->setHttpClient($guzzleClient);

    // Load previously authorized credentials from a file.
    $credentialsPath = expandHomeDirectory(CREDENTIALS_PATH).$email;
    if( file_exists( $credentialsPath ) ) {
        $accessToken = file_get_contents( $credentialsPath );
        //$refreshToken = file_get_contents( $credentialsPath . ".refresh" );
    } else {
        // Request authorization from the user.
        $authUrl = $client->createAuthUrl( );
        printf( "\n\nOpen the following link in your browser:\n--\n%s\n\n", $authUrl );
        print 'Enter verification code: ';
        $authCode = trim( fgets( STDIN ) );

        // Exchange authorization code for an access token.
        $accessToken = $client->authenticate( $authCode );
        $refreshToken = $client->getRefreshToken( );

        // Store the credentials to disk.
        if( !file_exists( dirname( $credentialsPath ) ) ) {
            mkdir( dirname( $credentialsPath ), 0700, true );
        }
        file_put_contents( $credentialsPath, json_encode( $accessToken ) );
        //file_put_contents( $credentialsPath . ".refresh", $refreshToken );
        printf( "Credentials saved to %s\n", $credentialsPath );
    }
    $client->setAccessToken( $accessToken );

    // Refresh the token if it's expired.
    if( $client->isAccessTokenExpired( ) ) {
        //$client->refreshToken( $refreshToken );
        $client->refreshToken( $client->getRefreshToken( ) );

        //file_put_contents( $credentialsPath, json_encode( $client->getAccessToken( ) ) );
    }
    return $client;
}


/**
 * @param $service Google_Service_Gmail Authenticated Gmail Message Service
 * @param $subject string The subject of the message
 * @param $messageText string The body of the message
 * @param $senderName string The name we want to send the message as
 * @param $recipient string The email address we are sedning to
 * @param $firstName string First name of recipient
 * @param $lastName string Last name of recipient
 * @return $message string The message
 */

function sendMessage(Google_Service_Gmail $service, $subject, $messageText, $senderName, $recepient, $firstName, $lastName) {
    // variable to cache the email address of the sender once it has been retrieved.
    global $myEmailAddress;
    $sender = "";
    
    $i = 0;
    for( $i = 0; $i < 5; $i++ ) {

        try {
            if( $myEmailAddress == "" ) {
                $myEmailAddress = $service->users->getProfile("me")->emailAddress;
            }
            $sender = $myEmailAddress;
            break;
        } catch( \Exception $exception ) {
            echo '\n *** Caught an exception.  Backing off for {$i*2} seconds.\n';
            sleep( $i*2 );
            continue;
        }
    }
    if( $i >= 5 ) {
        echo " We failed $i times on one line.  That is not good. Check whats up\n";
        exit();
    }


    $envelope["from"] = "$senderName <$sender>";
    $envelope["to"] = "$firstName $lastName <$recipient>";
    $envelope["subject"] = $subject;

    $part1["type"] = TYPETEXT;
    $part1["subtype"] = "plain";
    $part1["contents.data"] = $messageText;

    $body[1] = $part1;

    $mime = imap_mail_compose( $envelope, $body );
    $mime = rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');
    $message = new Google_Service_Gmail_Message();
    $message->setRaw($mime);

    $ret = "";
    for( $i = 0; $i < 5; $i++ ) {

        try {
            $service->users_messages->send( "me", $message );
        } catch( \Exception $exception ) {
            echo '\n*** Caught an exception.  Backing off for {$i*2} seconds.\n';
            sleep( $i*2 );
            continue;
        }
        break;
    }
    if( $i >= 5 ) {
        echo " We failed $i times on one line.  That is not good. Check whats up\n";
        exit();
    }


    return $ret;
}


