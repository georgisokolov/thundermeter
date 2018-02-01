#!/usr/bin/env php
<?php
require __DIR__ . './vendor/autoload.php';

define( 'APPLICATION_NAME', 'ThunderMeter' );
define( 'CREDENTIALS_PATH', '~/.credentials/ThunderMeter.json' );
define( 'CLIENT_SECRET_PATH', __DIR__ . '/client_secret.json' );

define( 'SCOPES', implode( ' ', array( Google_Service_Gmail::GMAIL_MODIFY, Google_Service_Gmail::GMAIL_READONLY ) ) );


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
 * Imports a message larger than 1 MB.  While it is possible to only
 * use this logic, calling import() directly for smaller messages saves
 * on API calls
 * @param $client Google_Client connected to the destination account
 * @param $service Google_Service_Gmail authenticated GmailService connected to the destination account
 * @param $user string $user user account.  can pass 'me' for authenitcated service
 * @param $newMessage Google_Service_Gmail_Message message that is to be moved
 * @param $rawMessage string base64encoded message contents
 */
function chunkedImport( $client, $service, $user, $newMessage, $rawMessage ) {
	echo "---Chunked Mode Activated.  Message size ". strlen( $rawMessage ) ." (";


	// chunk size is going to be set at 1MB
	$chunkSize = 1024 * 1024;	

	// Set the API to Defer mode so that we don't complete the transaction immediately
	$client->setDefer( true );

	// Import the message, but without any data 
	$ret = $service->users_messages->import( $user, $newMessage, array( 'internalDateSource' => "dateHeader", 'uploadType'=>"multipart" ) );	
	//$ret = $service->users_messages->insert( $user, $newMessage, "multipart", false, "dateHeader" );	

	// Apparently here we have to first decode the message
	$rawMessage = str_replace( array( '-', '_' ), array( '+', '/' ), $rawMessage );
	file_put_contents( "./what", $rawMessage );
	$decoded = "";
	for( $i = 0; $i < strlen( $rawMessage ); $i += 8192 ) {
		$decoded .=  base64_decode( substr( $rawMessage, $i, 8192 ) );
	}
	$rawMessage = $decoded;

	// Initialize the chunked file upload
	$media = new Google_Http_MediaFileUpload( $client, $ret, 'message/rfc822', $rawMessage, true, $chunkSize );

	$media->setFileSize( strlen( $rawMessage ) );
	
	// upload
	$status = false;
	while( $status == false ) {
		try {
			$status = $media->nextChunk();
		}  catch( Exception $e ) {
			echo "!)\nAn error occurred: {$e->getMessage()} \n";

			file_put_contents( "./what2", $rawMessage );
			//file_put_contents( "./what",  base64_decode( $rawMessage ) ); 
			echo "\n\n fin length: ". strlen( $rawMessage ) . "\n";
			exit();
			
		}

		echo "=";
	}
	echo ")\n";
	echo "---Done Upload\n";

	// Turn off Defferred and commit the transaction
	try {
		$client->setDefer( false );
	} catch( Exception $e ) {
		print "An error occurred: {$e->getMessage()} \n";
	}

		
	return $ret;
	
}

/**
 * Moves the specified message to another account
 * @param $client GmailClient connected to the destination account
 * @param $service GmailService authenticated GmailService connected to the destination account
 * @param $user string $user user account.  can pass 'me' for authenitcated service
 * @param GmailMessage Google_Service_Gmail_Message that is to be moved
 * @param array of additional labels (UNREAD is automatic) that need to be applied.  They must be already created
 * @return Google_Service_Gmail_Message the created message or empty string for error.
 */
function copyMessage( $client, $service, $user, $messageDetail, $extraLabels ) {
	$ret = "";

	print( "Importing....\n" );
	$newMessage = new Google_Service_Gmail_Message;

	$newMessage->setId( $messageDetail->id );
	$newMessage->labelIds = array();
	if( isset( $messageDetail->labelIds ) && in_array( "UNREAD", $messageDetail->labelIds) ) {
		$newMessage->labelIds =  array_merge( $newMessage->labelIds, array( "UNREAD" ) );
	}
	if( isset( $messageDetail->labelIds ) && in_array( "CHATS", $messageDetail->labelIds) ) {
		$newMessage->labelIds =  array_merge( $newMessage->labelIds, array( "CHATS" ) );
	}
	
	if( is_array( $extraLabels ) && sizeof( $extraLabels ) > 0 ) {
		$newMessage->labelIds =  array_merge( $newMessage->labelIds, $extraLabels );
        }
	
	
	// if the message is larger than 1 MB, use chunked upload	
	if( strlen ( $messageDetail->raw ) > 1 * 1024 * 1024  ) {
		$ret = chunkedImport( $client, $service, $user, $newMessage, $messageDetail->raw );
	} else {

		try {
			$newMessage->raw = $messageDetail->raw;
			$ret = $service->users_messages->import( $user, $newMessage, array( 'internalDateSource' => "dateHeader" ) );
		} catch( Exception $e ) {
			print "!!!An error occurred: {$e->getMessage()} \n";
			// rturning blank signals error
			return "";
		}
	}

	echo "---Success\n";
	return $ret;
}

/*
 * Deletes the specified message from the specified account
 * @param GmailService authenticated GmailService connected to the destination account
 * @param string $user user account.  can pass 'me' for authenitcated service
 * @param GmailMessage message that is to be deleted
 * @return error code
 */
function deleteMessage( $service, $user, $message ) {
	$ret = "";

	echo "Deleting...\n";
	try {
		$ret = $service->users_messages->trash( $user, $message->id );
	}  catch (Exception $e) {
		print 'An error occurred: ' . $e->getMessage();
	}
	echo "---success\n";

	return $ret;
}

/**
 * Lists the labels in the $service connected account
 * Checks to see if all the labels listed in $extraLables exit
 * Returns a list similar to extraLables except the names are replaced with GMail IDs so you can user them in queries
 * Exits if some of them don't
 @param $service string  the account whose labels we are going to process
 @param $user string  the email whose labels we are going to process
 @param $extraLabels array  optional array of labels whose existence we will check for
 @return $extraLabels array  with all label IDs instead of names.
*/
function processLabels( $service, $userEmail, $extraLabels = array() ) {
	$user = "me";
	echo "=== $userEmail Folder List ===\n";

	$results = $service->users_labels->listUsersLabels( $user );

	if ( count( $results->getLabels( ) ) == 0 ) {
		print "No labels found.\n";
	} else {
		print "Labels:\n";
		$unProcessedLabels = $extraLabels;
		foreach ( $results->getLabels( ) as $label ) {
			printf( "- %s\n", $label->getName( ) );

			// Get the actual labels
			$idx = array_search( trim( $label->getName( ) ), $extraLabels ); 
			// check if we it is one of urs
			if( $idx !== FALSE ) {
				// change the name with the id so we can actually use it
				$extraLabels[$idx] = $label->getId();
				// update unprocessed labels
				unset( $unProcessedLabels[$idx] ); 
			}
					
		}
		echo "\n";
		
		// Check if there are any unprocessedlabels left.  essentially that means
		// the labels passed to us were invalid
		if( sizeof( $unProcessedLabels ) > 0 ) {
			echo "\n The following labels could be found for $userEmail.  Please create those manually before proceeding.\n";
			
			foreach( $unProcessedLabels as $label ) {
				echo "  $label\n";
			}
			exit( -1 );
		}
	}
	return $extraLabels;
}



/**
 * Display the help message if the user requests it
*/
function displayHelp ( ) {
	$help = "";
	$help .= "\n  Thundermeter\n\n";
	$help .= "This programs is used to copy e-mail from one gmail account to another.  ";
	$help .= "Copy of the messages are made and then the copies in the original ";
	$help .= "account are deleted.  The user can select which messages to copy by ";
	$help .= "using standard gmail query syntax.\n";
	$help .= "It is assumed that the user has access to both of the source and ";
	$help .= "destination accounts.  Upon initiating execution the user will be prompted ";
	$help .= "to authenticate both accounts \n";
	$help .= "\n OPTIONS \n";
	$help .= "  -s <email@address> - source gmail hosted account from which we will ";
	$help .= "move messages\n";
	$help .= "  -d <email@address> - destination gmail hosted account to which we will ";
	$help .= "move messages\n";
	$help .= "  -q <string> - optional query string to select which messages will be moved\n";
	$help .= "  -l <comma, separated, list> - optinally labels to be applied to the messages";
	$help .= "in the destination account.  By default no labels are set except UNREAD as ";
	$help .= "appropriate.  The labels must be created ahead of time.";
	$help .= "  -h - print this message and exit.  \n\n ";
	$help .= "\n";
	$help .= "  EXAMPLE:\n";
	$help .= "  ./thundermeter -s orig@example.com -d dest@example.com -q \"after: 2016/4/1 before: 2016/5/1\" -l \"Archive-2016-April\"\n\n";

	echo "$help";
	exit( 0 );
};


/** 
 * Really simple function to save a couple of lines of typing on fatal errors
 * @param $errorString string error string
 */
 
function fatal( $errorString ) {
	echo "!!! Fatal Error: ". $errorString ."\n\n";
	exit( -1 );
}

/**
 * Goes through all the options passed into the program from the CLI and maeks sure we are good
 * @param $options array the options array returned by getopt()
 * @return array the (unaltered) options array returned by getopt()
 */
function processOpts( $options ) {
	// Check if help is called for
	if( isset( $options['h'] ) || isset( $options['help'] ) ) {
		displayHelp();
	}	

	// check if we got proper source email
	if( !isset( $options['s'] ) || isset( $options['source'] ) ) {
		fatal( "Source account is a mandatory argument" );
	}
    if( isset( $options['source'] ) ) {
	    $options['s'] = $options['source'];
    }

	if( FALSE === filter_var( $options['s'], FILTER_VALIDATE_EMAIL ) ) {
		fatal( "Source account string is not a valid email address" );
	} 
		
	// check if we got proper destination email
	if( !isset( $options['d'] ) || isset( $options['destination'] ) ) {
		fatal( "Source account is a mandatory argument" );
	}
    if( isset( $options['destination'] ) ) {
        $options['s'] = $options['destination'];
    }

	if( FALSE === filter_var( $options['d'], FILTER_VALIDATE_EMAIL ) ) {
		fatal( "Source account string is not a valid email address" );
	} 
	

	return $options;
}

/*function stacktrace_error_handler($errno,$message,$file,$line,$context)
{
    if($errno === E_WARNING) {
        debug_print_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
	exit();
    }
    return false; // to execute the regular error handler
}

set_error_handler("stacktrace_error_handler");
*/

/* ------ */
/* ------ */
/* ------ */
/* ------ */
/* ------ */

$options = processOpts( getopt( "s:d:q:l:h", array( "source", "destination", "query", "labels", "help" ) ) );

$nextPage = "";

// see if a query is passed  
$query = "";
if( isset( $options["q"] ) ) {
	$query = $options["q"];
}

// See if any extra labels need to be applied
if( isset( $options["l"] ) ) {
	$extraLabels = explode( ",", $options["l"] );
	// clean the white spaces off the beginning and end.
	foreach( $extraLabels as $idx => $label ) {
		$extraLabels[$idx] = trim( $label );
	} 
} else {
	$extraLabels = array();
}


// Get the API client and construct the service1 object.
$client1 = getClient( $options["s"] );
$client2 = getClient( $options["d"] );
$service1 = new Google_Service_Gmail( $client1 );
$service2 = new Google_Service_Gmail( $client2 );

// 
$user = 'me';


// Print the labels in the user's source account.
processLabels( $service1, $options["s"] );

// Print the labels in the user's source account.
$extraLabels = processLabels( $service2, $options["d"], $extraLabels );

// we will proactively refresh tokens every 1800 seconds;
$refreshTime = time() + 1800;

// main loop
do {
	$result = $service1->users_messages->listUsersMessages( 
		$user, 
		array( 
			'maxResults' => 100,
			'q' => $query,
			'pageToken' => $nextPage ) 
	);

	//print_r( $result );
	//print_r( $service2 );
	//exit();

	if( isset( $result->nextPageToken ) ) {
		$nextPage =  $result->nextPageToken;
	}
	if( !isset( $result->resultSizeEstimate ) || $result->resultSizeEstimate == 0 ) {
		echo "We got them all!\n\n";
		break;
	}
	echo "-- Getting the NEXT PAGE of Messages: $nextPage\n";

	$numMessages = count( $result );

	if ( $numMessages == 0 ) {
		print "No messages found.\n";
		break;
	} else {
		print "Messages in Page: $numMessages\n";
		foreach ( $result->getMessages( ) as $message ) {
			printf( "Message Id: %s\n", $message->getId( ) );
			try {
				$messageDetail = $service1->users_messages->get( $user, $message->getId( ), array( 'format' => "raw" ) );
			} catch( Exception $e ) {
				print "!!! An error occurred: {$e->getMessage()} \n";
				print "...skipping and continuing...\n";
				continue;
			}
			
			
			$msg = copyMessage( $client2, $service2, $options["d"], $messageDetail, $extraLabels );
			// only delete the old message if a message was actually copied
			if( $msg != "" ) {
				try {
					deleteMessage( $service1, $user, $messageDetail );
				} catch( Exception $e ) {
					print "!!! An error occurred: {$e->getMessage()} \n";
					print "...skipping and continuing...\n";
					continue;
				}

			} else {
				echo "!!! Copy failed so orinal won't be moved to Trash\n";
			}

			if( time() > $refreshTime ) {
				echo "-- REFRESHING TOKENS.... ";
				$client1 = getClient( $options["s"] );
				$client2 = getClient( $options["d"] );
				$service1 = new Google_Service_Gmail( $client1 );
				$service2 = new Google_Service_Gmail( $client2 );
				$refreshTime = time() + 1800;
				echo "DONE!\n";
			}
				
		}
	}
} while( $numMessages != 0 && $nextPage != "" );


?>
