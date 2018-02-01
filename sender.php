#!/usr/bin/env php
<?php
require __DIR__ . '/vendor/autoload.php';

define( 'APPLICATION_NAME', 'ThunderMeter' );
define( 'CREDENTIALS_PATH', '~/.credentials/ThunderMeter.json' );
define( 'CLIENT_SECRET_PATH', __DIR__ . '/client_secret.json' );

define( 'SCOPES', implode( ' ', array( Google_Service_Gmail::GMAIL_SEND, Google_Service_Gmail::GMAIL_MODIFY, Google_Service_Gmail::GMAIL_READONLY ) ) );

$myEmailAddress = "";


function process_error_backtrace($errno, $errstr, $errfile, $errline, $errcontext) {
    if(!(error_reporting() & $errno))
        return;
    switch($errno) {
        case E_WARNING      :
        case E_USER_WARNING :
        case E_STRICT       :
        case E_NOTICE       :
        case E_USER_NOTICE  :
            $type = 'warning';
            $fatal = false;
            break;
        default             :
            $type = 'fatal error';
            $fatal = true;
            break;
    }
    $trace = array_reverse(debug_backtrace());
    array_pop($trace);
    if(php_sapi_name() == 'cli') {
        echo 'Backtrace from ' . $type . ' \'' . $errstr . '\' at ' . $errfile . ' ' . $errline . ':' . "\n";
        foreach($trace as $item)
            echo '  ' . (isset($item['file']) ? $item['file'] : '<unknown file>') . ' ' . (isset($item['line']) ? $item['line'] : '<unknown line>') . ' calling ' . $item['function'] . '()' . "\n";
    } else {
        echo '<p class="error_backtrace">' . "\n";
        echo '  Backtrace from ' . $type . ' \'' . $errstr . '\' at ' . $errfile . ' ' . $errline . ':' . "\n";
        echo '  <ol>' . "\n";
        foreach($trace as $item)
            echo '    <li>' . (isset($item['file']) ? $item['file'] : '<unknown file>') . ' ' . (isset($item['line']) ? $item['line'] : '<unknown line>') . ' calling ' . $item['function'] . '()</li>' . "\n";
        echo '  </ol>' . "\n";
        echo '</p>' . "\n";
    }
    if(ini_get('log_errors')) {
        $items = array();
        foreach($trace as $item)
            $items[] = (isset($item['file']) ? $item['file'] : '<unknown file>') . ' ' . (isset($item['line']) ? $item['line'] : '<unknown line>') . ' calling ' . $item['function'] . '()';
        $message = 'Backtrace from ' . $type . ' \'' . $errstr . '\' at ' . $errfile . ' ' . $errline . ': ' . join(' | ', $items);
        error_log($message);
    }
    if($fatal)
        exit(1);
}

set_error_handler('process_error_backtrace');





/**
 * Get a new authorized API client.
 */
function getClient( $email ) {
    //$google_config = new Google_Config();
    //$google_config->setLoggerClass('Google_Logger_File');

    // FORCE CURL TO USE IPv4.  This avoids SSL errors...
    //$google_config->setClassConfig( 'Google_IO_Curl', 'options',
    //	array( 'CURLOPT_IPRESOLVE' => 'CURL_IPRESOLVE_V4',
    /*'CURLOPT_VERBOSE' => 'TRUE' */
    //	)
    //);

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
 * Expands the home directory alias '~' to the full path.
 * @param string $path the path to expand.
 * @return string the expanded path.
 */
function expandHomeDirectory( $path ) {
    $homeDirectory = getenv( 'HOME' );
    if ( empty( $homeDirectory ) ) {
        $homeDirectory = getenv( "HOMEDRIVE" ) . getenv( "HOMEPATH" );
    }
    return str_replace( '~', realpath( $homeDirectory ), $path );
}


/**
 * Display the help message if the user requests it
 */
function displayHelp ( )
{
    $help = "";
    $help .= "\n  SuperSender \n\n";
    $help .= "This programs is used to send an email to a number of addresses loaded from a csv file";
    $help .= "\n";
    $help .= "-s   --sender  <email@address>  The address of the account that will be sending the emails.\n";
    $help .= "-S   --sender-name <\"First Last\"> The name from which the emails will originate.\n";
    $help .= "-n   --names <fileName> The  CSV file with the destination emails.  Format is first,last,email\n";
    $help .= "-u   --subject <\"The subject\"> The subject of the email messages.  Use {} for substitutions.\n";
    $help .= "-m   --message <fileName> The name of the file containing the message.  USe {} for substitutions.\n";
    $help .= "-p   --no-pause  Normally the first email in every list is a test message to the sender after we which we pause.  This option disables the pause.\n";
    $help .= "\n";
    $help .= " The CSV file with destination emails must have 3 fields - FirstName, LastName, Email.";
    $help .= "\n";
    $help .= " The allowed substitutions are: \n";
    $help .= "    {first}\n";
    $help .= "    {last}\n";
    $help .= "    {email}\n";
    echo "$help";
    exit( 0 );
}


/**
 * Really simple function to save a couple of lines of typing on fatal errors
 * @param $string string error string
 */

function fatal( $string ) {
    echo "!!! Fatal Error: ". $string ."\n\n";
    exit( -1 );
}


/**
 * Goes through all the options passed into the program from the CLI and maeks sure we are good
 * @param $options array the options array returned by getopt()
 * @return $options array the options array returned, with the value of any longopts stored in their shortopt counterparts
 */
function processOpts( $options )
{
    // Check if help is called for
    if (isset($options['h']) || isset($options['help'])) {
        displayHelp();
    }

    // check if we got proper source email
    if (isset($options['sender'])) {
        $options['s'] = $options['sender'];
    }
    if (!isset($options['s']) ) {
        fatal("Sender email account is a mandatory argument");
    }
    // check if we got proper source email
    if (FALSE === filter_var($options['s'], FILTER_VALIDATE_EMAIL)) {
        fatal("Source account string is not a valid email address");
    }

    // Get the sender name if set
    if (isset($options['sender-name'])) {
        $options['S'] = $options['sender-name'];
    }

    // Get the subject if set
    if (isset($options['subject'])) {
        $options['u'] = $options['sender-name'];
    }

    // check if we got the name of a file
    if( isset( $options['names'] ) ) {
        $options['n'] = $options['names'];
    }
    if (!isset($options['n']) ) {
        //var_dump( $options );
        fatal("File name of CSV with names and addresses is mandatory" );
    }

    // check if we got the name of a file
    if( isset( $options['message'] ) ) {
        $options['m'] = $options['message'];
    }

    if (!isset($options['m']) ) {
        fatal("File name of CSV with names and addresses is mandatory" );
    }

    // check if we are going to pause after the first email
    if( isset( $options['no-pause'] ) ) {
        $options['p'] = true;
    } else {
        $options['p'] = false;
    }



    return $options;
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
function sendMessage( $service, $subject, $messageText, $senderName, $recipient, $firstName, $lastName ) {

    // variable to cache the email address of the sender once it has been retrieved.
    global $myEmailAddress;
    $sender = "";
    echo "2";
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


    echo "3";
    $envelope["from"] = "$senderName <$sender>";
    $envelope["to"] = "$firstName $lastName <$recipient>";
    $envelope["subject"] = $subject;

    $part1["type"] = TYPETEXT;
    $part1["subtype"] = "plain";
    $part1["contents.data"] = $messageText;

    $body[1] = $part1;

    $mime = imap_mail_compose( $envelope, $body );
    $mime = rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');
    echo "4";
    $message = new Google_Service_Gmail_Message();
    echo "5";
    $message->setRaw($mime);
    echo "6";

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

// process all the options
$options = processOpts( getopt( "s:n:u:S:m:ph",
    array( "sender:", "names:", "subject:", "sender-name:", "message:", "no-pause", "help" ) ) );

// Get the API client and construct the service1 object.
$client = getClient( $options["s"] );
$service = new Google_Service_Gmail( $client );

$user = 'me';

// we will proactively refresh tokens every 1800 seconds;
$refreshTime = time() + 1800;

$senderName = $options['S'];
$subject = $options['u'];

//Read in the message file
if( ( $fp = fopen( $options['m'], "r" ) ) === FALSE ) {
    fatal( "The file that is supposed to contain the message does not exist ");
}
$message = fread($fp, filesize($options['m']));
fclose($fp);

// Try to open the file with all the addresses
if( ( $fp = fopen( $options['n'], "r" ) ) !== FALSE ) {
    $line = 0;

    // Read in a line from the CSV file
    $first = true;
    while( $data = fgetcsv( $fp ) ){
        $line++;

        // Fail if insufficient number of fields read in
        $numFields = sizeof( $data );
        if( $numFields < 3 ) {
            fatal( "Line $line: Insufficient Number of fields - expected 3, found $numFields !");
        }

        // Send an email
        $firstName = trim($data[0]);
        $lastName = trim($data[1]);
        $emailAddress = trim($data[2]);

        //TODO: add a regex here
        if( $emailAddress == "" ) {
            echo "Line: $line - got a blank email address.  Continuing. ";
            continue;
        }

        // Make a copy of the original message and sub all templates
        $messageCopy = str_ireplace( array('{first}', '{last}', '{email}'), array( $firstName, $lastName, $emailAddress ), $message );
        $subjectCopy = str_ireplace( array('{first}', '{last}', '{email}'), array( $firstName, $lastName, $emailAddress ), $subject );

        if( preg_match( '/\{.*\}/', $messageCopy ) ){
            fatal( "Your message contains \{\} substitutions that cannot be resolved\n" );
        }
        if( preg_match( '/\{.*\}/', $subjectCopy ) ){
            fatal( "Your subject contains \{\} substitutions that cannot be resolved\n" );
        }

        echo "-";
        sendMessage( $service, $subjectCopy, $messageCopy, $senderName, $emailAddress, $firstName, $lastName );
        echo "Sent message to $firstName $lastName <$emailAddress>\n";

        // If no-pause is false, we want to stop after the first email
        if( $options['p'] == false && $first == true){
            echo "\n First email sent.  Please check if you have received it successfully and it looks good!\n";

            while( true ) {
                echo "Type 'yes' to continue or 'no' to abort\n";
                $handle = fopen ("php://stdin","r");
                $line = fgets($handle);
                if( trim($line) == 'no' ){
                    echo "ABORTING!\n";
                    exit;
                }
                if( trim( $line ) == 'yes' ){
                    break;
                }
                fclose($handle);
            }
        }
        $first = false;
    }
    echo "Done!  Sent $line emails sucessfully\n";

} else {
    fatal("Could not open file {$options['n']}.  Please check your path and try again ");
}

fclose( $fp );

