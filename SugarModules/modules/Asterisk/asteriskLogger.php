<?php
/**
 * Asterisk SugarCRM Integration
 * (c) KINAMU Business Solutions AG 2009
 *
 * Project Home: www.github.com/blak3r/yaai
 *
 * Parts of this code are (c) 2006. RustyBrick, Inc. http://www.rustybrick.com/
 * Parts of this code are (c) 2008 vertico software GmbH
 * Parts of this code are (c) 2009 abcona e. K. Angelo Malaguarnera E-Mail admin@abcona.de
 * Parts of this code are (c) 2011 Blake Robertson http://www.blakerobertson.com
 * Sugarforge: http://www.sugarforge.org/projects/yaai/
 * GitHub: http://www.github.com/blak3r/yaai
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License version 3 as published by the
 * Free Software Foundation with the addition of the following permission added
 * to Section 15 as permitted in Section 7(a): FOR ANY PART OF THE COVERED WORK
 * IN WHICH THE COPYRIGHT IS OWNED BY SUGARCRM, SUGARCRM DISCLAIMS THE WARRANTY
 * OF NON INFRINGEMENT OF THIRD PARTY RIGHTS.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more
 * details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program; if not, see http://www.gnu.org/licenses or write to the Free
 * Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA
 * 02110-1301 USA.
 *
 * You can contact KINAMU Business Solutions AG at office@kinamu.com
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 */

require 'helper_functions.php';

//
// Debug flags
//

// IF FollowMe == 0, then call popups are lost after call is answered on a cell phone.
// If FollowMe == 1, disadvantage is ... call popups don't appear until call is answered on RING GROUPS and QUEUES.
$FOLLOWME = 1;
$mysql_loq_queries = 0;
$mysql_log_results = 0;
$verbose_log = 0;
$log_memory_usage = 0;
$memory_usage_log_file = "mem_usage.csv";
$memory_usage_log_frequency_secs = 10*60;
$last_memory_log_entry = "";
$last_push_time=0;

// All Sugar timestamps are UTC
date_default_timezone_set('UTC');

//
// Say hello, setup include path(s)
//
define('sugarEntry', TRUE);
logLine("\n\n\n******** Asterisk Logger Starting " . getTimestamp() . "**************\n", true);

// Determine SugarCRM's root dir (we'll use this to find the config filez
$scriptRoot = dirname(__FILE__);
$sugarRoot = $scriptRoot . "/../../../";
print "[Config Settings]\n";
print " Sugar root set to [$sugarRoot]\n";
//set_include_path(get_include_path() . PATH_SEPARATOR . $sugarRoot . "include");
//print "# PHP include path set to [" . get_include_path() . "]\n";
// Specify a custom sugar root on commandline when in test mode (I used this to debug from eclipse)
if ($argc > 2) {
    $sugarRoot = $argv[2];
    if (!endsWith($sugarRoot, "/")) {
        $sugarRoot .= "/";
    }
    print " New sugar root is: " . $sugarRoot;
}


//
// Required libraries
//
require_once($sugarRoot . "include/nusoap/nusoap.php");

// Wrapper class auto logins in if session expires.
class SugarSoap extends nusoapclient {

    public $sessionid;
    public $auth_array;

    function __construct($endpoint, $something, $auth_array) {
        $this->auth_array = $auth_array;
        parent::__construct($endpoint, $something);
        $this->login();
    }

    function login() {
        $result = parent::call('login', $this->auth_array);
        $this->sessionid = $result['id'];
        if ($this->sessionid == -1) {
            logLine("! Soap login failed!\n");
            print_r($result);
        }
        return ($result);
    }

    function call($method, $params) {
        $params['session'] = $this->sessionid;
        $result = parent::call($method, $params);
        if (is_array($result) && array_key_exists("error", $result) && $result['error']['number'] != 0) {
            $this->login();
            $result = parent::call($method, $params);
        }
        //print_r($result);
        return ($result);
    }

}

//
// Pull in config file(s)
//
require_once($sugarRoot . 'config.php');
include_once($sugarRoot . 'config_override.php');

logLine(" Main Log is logging to: " . $sugar_config['asterisk_log_file'] . "\n");
$dial_events_log = $sugar_config['asterisk_event_log_file'];
if( !empty($dial_events_log) ) {
    logLine(" Dial Events Log is enabled and logging to: " . $dial_events_log );
    dev_clearDialEventsLog();
}
$asteriskServer = $sugar_config['asterisk_host'];
$asteriskManagerPort = (int) $sugar_config['asterisk_port'];
$asteriskUser = "Username: " . $sugar_config['asterisk_user'] . "\r\n";
$asteriskSecret = "Secret: " . $sugar_config['asterisk_secret'] . "\r\n";
$asteriskMatchInternal = $sugar_config['asterisk_expr'];

// Load localization if available
$locale_path = $sugarRoot . 'custom/modules/Asterisk/language/' . $sugar_config['default_language'] . '.lang.php';
if (file_exists($locale_path)) {
    logLine(" Found Language file for " . $sugar_config['default_language']  );
    include_once $locale_path;
}
else {
    logLine("### WARNING: No language file exists for: " .  $sugar_config['default_language'] . ".  Defaulting to english");
    logLine(" __See https://github.com/blak3r/yaai/wiki/How-to-Translate__ for translation instructions.");
    include_once( $sugarRoot . 'custom/modules/Asterisk/language/en_us.lang.php' );
}

// Make regex pattern compatible with preg_match
if (!startsWith($asteriskMatchInternal, '/')) {
    $asteriskMatchInternal = '/' . $asteriskMatchInternal . '/i';
}

// Fetch Asterisk dialprefix - must strip this from inbound callerIDs if set
$calloutPrefix = isset($sugar_config['asterisk_prefix']) ? $sugar_config['asterisk_prefix'] : "";
echo (" Callout prefix is [$calloutPrefix]\n");

$callinPrefix = isset($sugar_config['asterisk_dialinPrefix']) ? $sugar_config['asterisk_dialinPrefix'] : "";
echo (" Callin prefix is [$callinPrefix]\n");
echo (" Match Internal Regex = $asteriskMatchInternal\n");

// current directory
echo getcwd() . "\n";
chdir( "../../../" );
echo getcwd() . "\n";

require_once($sugarRoot . "include/entryPoint.php");
//$res = $GLOBALS['db']->query("select * from users", false);
//while ($row = $GLOBALS['db']->fetchByAssoc($res)) {
//    print $row['user_name'] . "\n";
//}
//
//$res = $GLOBALS['db']->query("select * from asterisk_log", false);
//while ($row = $GLOBALS['db']->fetchByAssoc($res)) {
//    print $row['id'] . "\n";
//}
//
//print "Selecting using wrapper query...\n";
//$res = db_checked_query("select * from asterisk_log");
//while ($row = $GLOBALS['db']->fetchByAssoc($res)) {
//    print "CHKD: " . $row['id'] . "\n";
//}
//
//$res = $GLOBALS['db']->query("select * from asterisk_log", false);
//while ($row = $GLOBALS['db']->fetchByAssoc($res)) {
//    print $row['id'] . "\n";
//}


//
// Connect to Database
//
logLine("[Database Connection]\n");
logLine(" Selecting DB Name: {$sugar_config['dbconfig']['db_name']}\n");
//$sql_connection = mysql_connect($sugar_config['dbconfig']['db_host_name'], $sugar_config['dbconfig']['db_user_name'], $sugar_config['dbconfig']['db_password']);
//$sql_db = mysql_select_db($sugar_config['dbconfig']['db_name']);

// Prune asterisk_log
// Note only use this for development
// mysql_query('DELETE FROM asterisk_log');
// Set all MySQL dates to UTC
db_checked_query("SET time_zone='00:00'");
purgeExpiredEventsFromDb();

// Get SOAP config
$sugarSoapEndpoint = $sugar_config['site_url'] . "/soap.php"; //"/soap.php";
$sugarSoapUser = $sugar_config['asterisk_soapuser'];
$sugarSoapCredential = md5($sugar_config['asterisk_soappass']);

// Here we check if LDAP Authentication is used, if so we must build credential differently
$q = db_checked_query('SELECT VALUE FROM config WHERE category=\'system\' AND name=\'ldap_enabled\'');
$r = $GLOBALS['db']->fetchByAssoc($q);
if ($r['value'] != 1) {
    $sugarSoapCredential = md5($sugar_config['asterisk_soappass']);
} else {
    logLine("Using LDAP credentials for SOAP.");
    $q = db_checked_query('SELECT VALUE FROM config WHERE category=\'ldap\' AND name=\'enc_key\'');
    $r = $GLOBALS['db']->fetchByAssoc($q);
    $ldap_enc_key = substr(md5($r['value']), 0, 24);
    $sugarSoapCredential = bin2hex(mcrypt_cbc(MCRYPT_3DES, $ldap_enc_key, $sugar_config['asterisk_soappass'], MCRYPT_ENCRYPT, 'password'));
}

// Added this while loop to keep retrying the soap login b/c I found when I started it through daemon script...
// despite specifying apache as a dependency... it was failing to connect... (this will keep retrying login until it's successful).
// Probably should have this script reload the SOAP config from config.php. Wasn't sure how to do that since it's already been
// included at top of file in require_once.
$successfullyLoggedInThroughSoap = false;
while (!$successfullyLoggedInThroughSoap) {
    //
    // And finally open a SOAP connection to SugarCRM
    //
    logLine("[SOAP Connection]\n");
    logLine(" Trying SOAP login endpoint=[$sugarSoapEndpoint] user=[$sugarSoapUser] password=[$sugarSoapCredential]\n");

    $auth_array = array(
        'user_auth' => array(
            'user_name' => $sugarSoapUser,
            'password' => $sugarSoapCredential
        )
    );
    $soapClient = new SugarSoap($sugarSoapEndpoint . '?wsdl', true, $auth_array); // This method logs in also
    $soapSessionId = $soapClient->sessionid;
    $userGUID = $soapClient->call('get_user_id', array(
        $soapSessionId
    ));

    if (is_array($userGUID) && array_key_exists("error", $userGUID) && $userGUID['error']['number'] != 0) {
        logLine(" **WARNING Unable to make initial SOAP Call " . $userGUID['error']['number'] . " " . $userGUID['error']['name'] . " // " . $userGUID['error']['description'] . "**\n");
        logLine(" Retrying in 5 seconds...\n\n");
        sleep(5);
    }
    // This case might be obsolete at this point...
    else if (empty($userGUID) || empty($soapSessionId) || $userGUID == -1) {
        logLine(" __FATAL: SOAP login failed, something didn't get set by login... check your site_url, and make sure sugarcrm is running. SessionID:" . $soapSessionId . " user=" . $auth_array['user_auth']['user_name'] . " GUID=" . $userGUID . "__\n");
        logLine(" Sleeping for 5 seconds then retrying...\n\n");
        sleep(5);
    } else {
        logLine(" Successfully logged into Sugar via SOAP! SessionId=" . $soapSessionId . " user=" . $auth_array['user_auth']['user_name'] . " GUID=" . $userGUID . "\n");
        $successfullyLoggedInThroughSoap = true;
    }
}



if ($argc > 1 && $argv[1] == "test") {
    // MODIFY these variables to help diagnose misc incompatibilities between your configuration / sugarcrm edition.
    $ext1 = 51;
    $ext2 = 52;
    //$extensions = (51,52,207);
    $phones = array('4102152497', '4108464565', 'sdfasdf', '(267) 222-8385', '2672228385');

    print "Entered test mode!";

    mt_start();
    $obj = findSugarObjectByPhoneNumber("4102152497");
    $dur_oldMethod = mt_end();

    mt_start();
    $obj = findSugarBeanByPhoneNumber("4102152497");
    $dur_newMethod = mt_end();

    logLine("Old / New: $dur_oldMethod  $dur_newMethod");

    print_r(findSugarBeanByPhoneNumber("7607058888") );

    $obj = findSugarObjectByPhoneNumber("4108464565");
    print "findUserByAsteriskExtension(51) returned: " . findUserByAsteriskExtension("51") . "\n";
    print "findUserByAsteriskExtension(207) returned: " . findUserByAsteriskExtension("207") . "\n";
    print "findUserByAsteriskExtension(710) returned: " . findUserByAsteriskExtension('710') . "\n";
    findUserByAsteriskExtension('206');

    foreach ($phones as $currPhone) {
        $obj = findSugarObjectByPhoneNumber($currPhone);
        if ($obj === FALSE) {
            print "!!! DIDN'T FIND OBJ for $currPhone\n";
        } else {
            print " For $currPhone I found: " . $obj['values']['id'] . ' ' . $obj['values']['first_name'] . ' ' . $obj['values']['last_name'] . "\n";
        }
    }

    // Test for memory leaks...
    $cnt = 0;
    gc_enable();
    $GLOBALS['_transient']['static']['nusoap_base']->globalDebugLevel = 0;
    $lastMem = 0;
    while( $cnt++ < 1000 ) {
        $set_entry_params = array(
            'session' => $soapSessionId,
            'module_name' => 'Calls',
            'name_value_list' => array(
                array(
                    'name' => 'name',
                    'value' => $mod_strings['YAAI']['CALL_AUTOMATIC_RECORD']
                ),
                array(
                    'name' => 'status',
                    'value' => $mod_strings['YAAI']['CALL_IN_LIMBO']
                ),
                array(
                    'name' => 'assigned_user_id',
                    'value' => '1'
                )
            )
        );
        $soapResult = $soapClient->call('set_entry', $set_entry_params);
        unset($set_entry_params);
        unset($soapResult);
        $currMem = memory_get_usage() ;

        if( $lastMem > $currMem ) {
            logLine("\n\nmemory usage decreased!!!  $lastMem --> $currMem\n\n");
        }

        $lastMem = $currMem;
        if( $cnt % 10 == 0 ) {
            logLine( "mem usage: " . memory_get_usage() . "\n");
        }
    }
    exit;
}


// BR: Added this while loop to keep logging in to AMI if asterisk goes down.
while (true) {

    /*
    $cString = "tls://ast2.alertus.com:5039";
    $context = stream_context_create();
    //$errno = 0;
    //$errstr = '';
    $amiSocket = stream_socket_client(
        $cString, $errno, $errstr,
        5000, STREAM_CLIENT_CONNECT, $context
    );
    if ($sock === false) {
        print('Error connecting to ami: ' . $errstr);
    }
      //  $amiSocket = fsockopen('tls://' . $asteriskServer, $asteriskManagerPort, $errno, $errstr, 5);
  //  $asteriskManagerPort = 5038;
    */

    logLine("[Asterisk Manager Interface (AMI) Connection]\n");
    $amiSocket = fsockopen($asteriskServer, $asteriskManagerPort, $errno, $errstr, 5); // connect to Asterisk server
    if (!$amiSocket) {
        logLine(" __ ERROR $errno connecting to Asterisk: $errstr __");
        sleep(5); // retry connecting
        continue;
    } else {
        logLine(" Successfully opened socket connection to $asteriskServer:$asteriskManagerPort\n");
    }

    // Here we extract the AMI Version Number and set a varible with it for creating special if cases for special versions of AMI in the future.
    $result = AMI_ReadResponse($amiSocket, 2000000);
    logLine(" AMI Version Info:\n" . markdown_indent($result)); // Prints the AMI Version
    if (preg_match("/Call Manager\/(\d\.\d)/", $result, $ver_matches)) {
        $managerVersion = $ver_matches[1];

        if ($managerVersion === "1.1") {
            logLine(" Supported AMI version: $managerVersion Detected");
        }
        else if($managerVersion === "1.0") {
            logLine("\n __READ!!! You have a partially supported AMI version: $managerVersion Detected__");
            logLine(" __Some features like Ring Groups and Queues probably will not work.__\n\n ");
        }
    } else {
        logLine(" __WARNING: Unable to detect the manager version. Setting to 1.1 and hoping for the best. But, this is probably an error__");
        $managerVersion = "1.1";
    }

    // Perform AMI Login command
    fputs($amiSocket, "Action: Login\r\n");
    fputs($amiSocket, $asteriskUser);
    fputs($amiSocket, $asteriskSecret);
    fputs($amiSocket, "Events: call,hud\r\n\r\n"); // to monitor just call data, for Asterisk Manager 1.0 remove hud
    $result = AMI_ReadResponse($amiSocket, 2000000);
    logLine(" AMI Login action raw response:\n" . markdown_indent($result));
    if (AMI_WasCmdSuccessful($result)) {
        logLine(" AMI Login was a *success!*");
        logLine("Waiting for call events...");
    } else {
        logLine(" __ERROR: AMI Login FAILED__, Depending on your asterisk version helpful info may be above.\n **Check Asterisk Username / Password in config, then verify AMI user has proper permissions in manager.conf**\n\n");
        sleep(5);
        continue;
    }

    $event = '';
    $stack = 0;

    $event_started = false;

    $start = NULL;
    $timeout = ini_get('default_socket_timeout');

    stream_set_timeout($amiSocket, 60); // sets timeout to 60 seconds.
    $consecutiveFailures = 0;
    $queueChannels = array();

    // Keep a loop going to read the socket and parse the resulting commands.
    // Apparently there is no good way to detect if socket is still alive???
    // This is my hack... if we fail 60 times in a row we reconnect to manager...
    // I suspect that fgets will return very quickly if socket error has occurs
    // So, it'll reach 60 very quickly and then force relogin.
    // Otherwise, every hour it'll just relogin.
    // Perhaps you can check socket error some other way then socket_read?
    // All I know is this reconnect method has made my asteriskLogger a lot more stable.
    while ($consecutiveFailures < 60 && !safe_feof($amiSocket, $start) && (microtime(true) - $start) < $timeout) {
        $buffer = fgets($amiSocket, 4096);
         //echo("# Read " . strlen($buffer) . " " . $buffer . "\n");
        if ($buffer === FALSE) {
            logLine(getTimestamp() . " Patiently Waiting...! (mem_used: " . memory_get_usage() . ")\n");
            purgeExpiredEventsFromDb();
            $consecutiveFailures++;
        } else {
            $consecutiveFailures = 0;
            if ($buffer == "\r\n") { // handle partial packets
                $event_started = false;
                // parse the event and get the result hashtable
                $e = getEvent($event);
                dumpEvent($e); // prints to screen
                //if ($e['Event'] == 'Join' && !empty($e['Queue']) /*&& in_array($e['Queue'], $allowedQueueIds)*/ )
                //{
                // $queueChannels[ AMI_getUniqueIdFromEvent($e) ] = $e['Channel']; // TODO: This array will grow indefinitely... the data put into it is pretty small so probably fine for now but would be best to have a expiration policy.
                // // Easy solution would be to test during the hangup event... IF( isset($queueChannels[ $e['UniqueID'] ] ) remove the index for $e['UniqueID']
                // logLine("Incoming Queue Event, channel = " . $e['Channel']);
                //}

                //
                // Call Event
                // AMI 1.0 doesn't have SubEvent
                if (($e['Event'] == 'Dial' && ($e['SubEvent'] == 'Begin' || !isset($e['SubEvent']))) ||
                    ($e['Event'] == 'Join' && !empty($e['Queue'])))
                {
                    purgeExpiredEventsFromDb(); // clears out db of old events... also called when timeouts occcur

                    logLine("! Dial Event src=" . $e['Channel'] . " dest=" . $e['Destination'] . "\n"); //Asterisk Manager 1.1

                    dev_LogString('Event begin' . getTimestamp() . " " . $e['Event']);

                    $eChannel = $e['Channel'];

                    // Attempt to make compatible with AMI 1.0
                    if (!empty($e['Source'])) {
                        $eChannel = $e['Source'];
                    }

                    $eDestination = $e['Destination'];

                    if (empty($e['DestUniqueID']) && $e['Event'] == 'Join') {
                        logLine("DestUniqueID is empty, this must be a queue call... or logic error");
                        $e['DestUniqueID'] = AMI_getUniqueIdFromEvent($e); // We set destination id because Join events don't set them, and the destination id is what is used to lookup hangup events.
                    }

                    //
                    // Before we log this Dial event, we create a corresponding object in Calls module.
                    // We'll need this later to record the call when finished, but create it right here
                    // to get the ID
                    //
                    if( $sugar_config['asterisk_logger_sql_mode'] ) {
                        // SQL MODE
                        $callRecordId = createCallId();
                        $query = "INSERT INTO calls (id, name, status, assigned_user_id) VALUES ('{$callRecordId}', '{$mod_strings['YAAI']['CALL_AUTOMATIC_RECORD']}', '{$mod_strings['YAAI']['CALL_IN_LIMBO']}', '{$userGUID}')";
                        db_checked_query($query);
                    }
                    else {
                        // SOAP MODE
                        $set_entry_params = array(
                            'session' => $soapSessionId,
                            'module_name' => 'Calls',
                            'name_value_list' => array(
                                array(
                                    'name' => 'name',
                                    'value' => $mod_strings['YAAI']['CALL_AUTOMATIC_RECORD']
                                ),
                                array(
                                    'name' => 'status',
                                    'value' => $mod_strings['YAAI']['CALL_IN_LIMBO']
                                ),
                                array(
                                    'name' => 'assigned_user_id',
                                    'value' => $userGUID
                                )
                            )
                        );
                        $soapResult = $soapClient->call('set_entry', $set_entry_params);
                        //print_r( $soapResult );
                        $callRecordId = $soapResult['id'];
                    }
                    logLine("! Successfully created CALL record with id=" . $callRecordId . "\n");
                    dev_logString("! Successfully created CALL record with id=" . $callRecordId . "\n");
                    //dev_LogString('Replaced SOAP Call complete' . getTimestamp() . " " . $e['event']);

                    $call = NULL;

                    $tmpCallerID = trim(AMI_getCallerIdFromEvent($e)); //Asterisk Manager 1.0 $e['CallerID']
                    // Typically for outbound calls there are NewCallerID events which contain the phone number dialed.
                    // This isn't the case on POTS lines.
                    // The dialstring will be like g0/14101234567 for outbound calls and 14101234567 for inbound
                    // Regex only matches the outbound case since in the inbound case the CallerIDNum variable is set properly.
                    // Note: this cases also seems to happen on the INTERNAL inbound call events to Ring Groups which is harmless.
                    if (!empty($e['Dialstring'])) {
                        if (preg_match("/(.*?\/)N?(\d+)/", $e['Dialstring'], $ds_matches)) {
                            $tmpCallerID = $ds_matches[2];
                            logLine(" CallerID set from Dialstring to: " . $tmpCallerID);
                        }
                    }

                    // Fix for issue on some asterisk 1.8 boxes where CallerId on click to dial is not set.
                    // See https://github.com/blak3r/yaai/issues/75
                    if ($tmpCallerID == '<unknown>' && !empty($e['ConnectedLineNum'])) {
                        $tmpCallerID = trim($e['ConnectedLineNum']);

                        // If Call ID is blocked it comes in as "<unknown>"
                        if( $tmpCallerID == "<unknown>") {
                            $tmpCallerID = "BLOCKED";
                        }

                        logLine(" CallerID set from ConnectedLineNum to $tmpCallerID");
                    }

                    if (startsWith($tmpCallerID, $calloutPrefix)) {
                        logLine(" Stripping callout prefix: $calloutPrefix\n");
                        $tmpCallerID = substr($tmpCallerID, strlen($calloutPrefix));
                    }

                    if (startsWith($tmpCallerID, $callinPrefix)) {
                        logLine(" Stripping callin prefix: $calloutPrefix\n");
                        $tmpCallerID = substr($tmpCallerID, strlen($callinPrefix));
                    }

                    logLine(" CallerID is: $tmpCallerID\n");

                    $rgDetectRegex = "/" . $sugar_config['asterisk_rg_detect_expr'] . "/i";
                    $rgCellRingRegex = "/" . $sugar_config['asterisk_rg_cell_ring_expr'] . "/i"; // This detects in a RG when an outside line is called (usually for a cellphone)... for some reason the cell shows up as the Channel (aka the source)... We detect this by finding a number thats at least 7-10 characters long..
                    // Check if both ends of the call are internal (then delete created (** Automatic record **) record)
                    // 2nd condition looks for Local/RG-52-4102152497
                    /* if ((preg_match($asteriskMatchInternal, $eChannel) && preg_match($asteriskMatchInternal, $eDestination)) ||
                        preg_match($rgDetectRegex, $eDestination) ||
                        preg_match($rgCellRingRegex, $eChannel)) {
                        deleteCall($callRecordId);
                        logLine("INTERNAL call detected, Deleting Call Record $callRecordId\n");
                    } else {
                        //Asterisk Manager 1.1 (If the call is internal, this will be skipped)
                        if (preg_match($asteriskMatchInternal, $eChannel) && !preg_match($asteriskMatchInternal, $eDestination)) {
                            $userExtension = extractExtensionNumberFromChannel($eChannel);
                            //check whether call was already input during click to dial
                            $asteriskId = AMI_getUniqueIdFromEvent($e);
                            dev_logString("Entering outbound inbound");

                            if(findLoggedCallByAsteriskId($asteriskId) === FALSE){
                                logLine ($asteriskId . "updated inside false");
                                $query = sprintf("INSERT INTO asterisk_log (asterisk_id, call_record_id, channel, remote_channel, callstate, direction, CallerID, timestamp_call,user_extension) VALUES('%s','%s','%s','%s','%s','%s','%s',%s,'%s')", AMI_getUniqueIdFromEvent($e)/*BR: In an attempt to make Dial End work for Outbound calls switching this over to use Unique ID... I have no idea why DestId was used originally... TBD $e['DestUniqueID'], $callRecordId, $eChannel, $eDestination, 'NeedID', 'O', $tmpCallerID, 'FROM_UNIXTIME(' . time() . ')', $userExtension);
                                dev_logString("Insert Outbound");
                                $callDirection = 'Outbound';
                                logLine("OUTBOUND state detected... $asteriskMatchInternal is astMatchInternal eChannel= " . $eChannel . ' eDestination=' . $eDestination . "\n");
                            }else{
                                //update record created from the click to dial with the callRecordID
                                logLine ($asteriskId . "updated outside false");
                                $query = "UPDATE asterisk_log SET asterisk_dest_id = '{$e['DestUniqueID']}', callerID = '{$tmpCallerID}' , call_record_id = '{$callRecordId}', remote_channel = '{$eDestination}' WHERE asterisk_id = '{$asteriskId}'";
                                dev_logString("Update Outbound");
                                $callDirection = 'Outbound';
                                logLine("OUTBOUND CLICK-TO-DIAL state detected... $asteriskMatchInternal is astMatchInternal eChannel= " . $eChannel . ' eDestination=' . $eDestination . "\n");
                            }
                        } else if (!preg_match($asteriskMatchInternal, $eChannel)) { */
                            $userExtension = extractExtensionNumberFromChannel($eDestination);
                            if( $e['Event'] == 'Join' && !empty($e['Queue'])) {
                                $userExtension = $e['Queue'];
                            }

                            $inboundExtension = NULL;
                            if (!empty($e['Queue']) ) {
                                $inboundExtension = $e['Queue'];
                            }
                            else {
                                // Extract from eDestination
                                $inboundExtension = extractExtensionNumberFromChannel($eDestination);
                            }

                            $userDevice = $userExtension; // Not completely thought out... might be more cases to consider.


                            // Here we are looking back in time...
                            // We are adding some special logic to handle the case of an inbound call being answered by someone on their cell phone.
                            // We look into the call log to see if there are events with the same asterisk_id (which implies it's the same original call that's branched off to several different extensions).
                            // We then look at the original call record's inbound extension and use it!
                            if( $FOLLOWME == 1) {
                                $asteriskId = AMI_getUniqueIdFromEvent($e);
                                $query = sprintf("SELECT user_extension, inbound_extension FROM asterisk_log WHERE asterisk_id = '$asteriskId' ORDER BY id ASC");
                                $res = db_checked_query($query);
                                $prevRowDetails = db_fetchAssoc($res);
                                if( !empty( $prevRowDetails['inbound_extension'])) {
                                    $inboundExtension = $prevRowDetails['inbound_extension'];
                                    dev_logString("Using $inboundExtension as this entries inbound extension");

                                    // We're detecting if this is calling an external line... like a cell
                                    if( $userExtension > 7 ) {
                                        dev_logString("Using $userExtension as this USER ext (instead of $userExtension)");
                                        $userExtension = $prevRowDetails['user_extension'];
                                    }
                                }
                            }
                            logLine("  inbound_extension = " . $inboundExtension );

                            $query = sprintf("INSERT INTO asterisk_log (asterisk_id, call_record_id, channel, remote_channel, callstate, direction, CallerID, timestamp_call, asterisk_dest_id,user_extension,inbound_extension,user_device) VALUES('%s','%s','%s','%s','%s','%s','%s',%s,'%s','%s','%s','%s')", AMI_getUniqueIdFromEvent($e), $callRecordId, $eDestination, $eChannel, 'Dial', 'I', $tmpCallerID, 'FROM_UNIXTIME(' . time() . ')', $e['DestUniqueID'], $userExtension, $inboundExtension, $userDevice);
                            $callDirection = 'Inbound';
                            dev_logString("Insert Inbound");
                            dev_LogString('Query created' . getTimestamp());
                            logLine("Inbound state detected... $asteriskMatchInternal is astMatchInternal eChannel= " . $eChannel . ' eDestination=' . $eDestination . "\n");

                           // }
                        db_checked_query($query);
                        dev_LogString('Query complete' . getTimestamp() . " " . $e['Event']);


                        //
                        // Update CALL record with direction...
                        //

                        if( $sugar_config['asterisk_logger_sql_mode'] ) {
                            $query = "UPDATE calls SET direction = '{$callDirection}' WHERE id = '{$callRecordId}'";
                            db_checked_query($query);
                        }
                        else {
                            $set_entry_params = array(
                                'session' => $soapSessionId,
                                'module_name' => 'Calls',
                                'name_value_list' => array(
                                    array(
                                        'name' => 'id',
                                        'value' => $callRecordId
                                    ),
                                    array(
                                        'name' => 'direction',
                                        'value' => $callDirection
                                    )
                                )
                            );
                            $soapResult = $soapClient->call('set_entry', $set_entry_params);
                        }
                   // }
                }

                //
                // NewCallerID for Outgoing Call
                //
                // NewCallerid == 1.1, Newcallerid == 1.0
                if ($e['Event'] == 'NewCallerid' || $e['Event'] == 'Newcallerid') {

                    $id = AMI_getUniqueIdFromEvent($e);
                    $tmpCallerID = AMI_getCallerIdFromEvent($e);
                    if ((strlen($calloutPrefix) > 0) && (strpos($tmpCallerID, $calloutPrefix) === 0)) {
                        logLine("* Stripping prefix: $calloutPrefix");
                        $tmpCallerID = substr($tmpCallerID, strlen($calloutPrefix));
                    }
                    logLine(" CallerID Changed to: $tmpCallerID\n");
                    $query = "UPDATE asterisk_log SET CallerID='" . $tmpCallerID . "', callstate='Dial' WHERE asterisk_id='" . $id . "'";
                    db_checked_query($query);
                }

                //
                // CLICK TO DIAL OUTBOUND HANDLING - WE ALREADY KNOW THE ASSOCIATED RECORD SO NO NEED TO LOOK IT UP
                //

                if ($e['Event'] == 'NewAccountCode' && strpos($e['AccountCode'], "LICKTODIAL") != false) {
                    $id = AMI_getUniqueIdFromEvent($e);
                    if (findLoggedCallByAsteriskId($id) === FALSE) {
                        logLine("The AccountCode is" . $e['AccountCode']);
                        logLine(" Account Code ID is: $id\n");
                        $time = time();
                        $callerID = stripExtensionFromAccountCode($e['Channel']);
                        $result = findCallDetailsByClickToDialString($e['AccountCode']);
                        $beanLink = build_link($result['module'], $result['record']);
                        $query = "INSERT INTO asterisk_log (asterisk_id, callstate, direction, callerID, channel, timestamp_call, bean_module, bean_id, bean_name, bean_link, user_extension) "
                            . "VALUES('{$id}', 'Connected', 'O', '{$result['number']}', '{$e['Channel']}', FROM_UNIXTIME('{$time}'), '{$result['dbtable']}', '{$result['record']}', '{$result['query']['name']}', '{$beanLink}', '{$callerID}')";
                        logLine($query);
                        //. "bean_link='" . $result['query'][''] . "' "
                        //. "bean_description='" . $result['parent_module'] . "' "
                        //. "bean_description='" . $result['parent_id'] . "' "
                        //. "bean_description='" . $result['parent_name'] . "' "
                        //. "bean_description='" . $result['parent_link'] . "'";
                        db_checked_query($query);
                    }
                }


                // Had to switch to using Dial End commands because when using hangups I couldn't do calls to cell phones properly... (basically there are so many
                // hangup events it killed me...
                // Queues have the opposite issue... I can't detect the end of a Queue call unfortuntely...
                // Queues -- Could not use Dial End, only Hangup would work.


                //
                // Process "Hangup" events
                // Yup, we really get TWO hangup events from Asterisk! (Even more with Ringgroups)
                // Obviously, we need to take only one of them....
                //
                // Asterisk Manager 1.1
                /*$e['Event'] == 'Hangup'*/
                if (($e['Event'] == 'Dial' && $e['SubEvent'] == 'End') ||
                    ($e['Event'] == 'Hangup' /*&& preg_match( '/from-queue/', $e['Channel'])*/ ))  {
                    $id = AMI_getUniqueIdFromEvent($e);
                    logLine(" In DialEnd... $id");
                    $query = "SELECT call_record_id,direction,bean_module,bean_id,user_extension,inbound_extension FROM asterisk_log WHERE asterisk_dest_id = '$id' OR asterisk_id = '$id'";
                    $result = db_checked_query($query);
                    $direction = db_fetchAssoc($result);
                    //var_dump($direction);
                    if ($direction['direction'] == "I") {
                        $callDirection = "Inbound";
                    } else {
                        $callDirection = "Outbound";
                    }

                    //logLine( print_r($direction,true) );
                    // TODO verify configurator boolean issues will not present a problem for this.
                    if(empty($direction['user_extension']) ||
                            !findUserByAsteriskExtension($direction['user_extension']))
                    {
                        logLine("  ## Deleting callid = " . $direction['call_record_id'] . " because it didn't match any user extension");
                        deleteCall( $direction['call_record_id']);
                    }
                    else if ($callDirection == "Outbound") { //Outbound callhandling
                        //
                        // Fetch associated call record
                        //
                        $callRecord = findCallByAsteriskId($id);
                        if ($callRecord) {
                            logLine("### [$id] FOUND outbound CALL\n");
                            //
                            // update entry in asterisk_log...
                            //
                            $rawData = $callRecord['bitter']; // raw data from asterisk_log
                            $query = sprintf("UPDATE asterisk_log SET callstate='%s', timestamp_hangup=%s WHERE asterisk_id='%s'", //asterisk_dest_id was asterisk_id
                                'Hangup', 'FROM_UNIXTIME(' . time() . ')', $id);
                            dev_logString("Hungup $id");
                            $updateResult = db_checked_query($query);
                            if ($updateResult) {
                                $assignedUser = findUserIdFromChannel($rawData['channel']);

                                //
                                // ... on success also update entry in Calls module
                                //
                                //
                                // Calculate call duration...
                                //
                                $failedCall = FALSE;
                                $hangupTime = time();
                                $callDurationRaw = 0; // call duration in seconds, only matters if timestamp_link != NULL
                                if ($rawData['timestamp_link'] != NULL) {
                                    $callStartLink = strtotime($rawData['timestamp_link']);
                                    $callDurationRaw = $hangupTime - $callStartLink;
                                } else {
                                    $failedCall = TRUE;
                                }
                                $callStart = strtotime($rawData['timestamp_call']);

                                logLine(" [$id] Measured call duration is $callDurationRaw seconds\n");

                                // Recalculate call direction in minutes
                                $callDuration = (int) ($callDurationRaw / 60);
                                $callDurationHours = (int) ($callDuration / 60);
                                //$callDurationMinutes = ceil($callDuration / 60); //voor afronden naar boven.
                                $callDurationMinutes = ($callDuration % 60);

                                //
                                // Calculate final call state
                                //
                                $callStatus = NULL;
                                $callName = NULL;
                                $callDescription = "";

                                // BR: 3/16/2012 I originally had this check to make sure call was longer then 5 seconds... I don't know why. Whey you have callStatus of Missed it creates a task which is undesirable.
                                // So i'm commenting it out. If it's April and I still haven't deleted this comment it's safe to delete this code.
                                //if (!$failedCall) {
                                $callStatus = 'Held';
                                $callName = $mod_strings['YAAI']['ASTERISKLBL_GOING_OUT'];

                                // This means call description was updated through AJAX so lets not overwrite the subject/description already assigned to the call.
                                if (!empty($callRecord['sweet']['description'])) {
                                    $callName = $callRecord['sweet']['name'];
                                    $callDescription = $callRecord['sweet']['description'];
                                }
                                // } else {
                                // $callStatus = 'Missed';
                                // $callName = $mod_strings['YAAI']['CALL_NAME_MISSED'];
                                // $callDescription = "{$mod_strings['YAAI']['CALL_DESCRIPTION_MISSED']} ({$e['Cause-txt']}\n";
                                // $callDescription .= "------------------\n";
                                // $callDescription .= sprintf(" %-20s : %-40s\n", $mod_strings['YAAI']['CALL_DESCRIPTION_PHONE_NUMBER'], $rawData['callerID']);
                                // if( $rawData['opencnam'] ) {
                                // $callName .= " - " . $rawData['opencnam'];
                                // $callDescription .= sprintf(" %-20s : %-40s\n", $mod_strings['YAAI']['CALL_DESCRIPTION_CALLER_ID'], $rawData['opencnam']);
                                // }
                                // logLine("Adding OUTBOUND Failed Call, id=$id, call_id = " . $callRecord['sweet']['id'] . "\n");
                                // }
                                // Establish Relationships with the Call and Contact/Account
                                $beanID = NULL;
                                $beanType = NULL;
                                $parentID = NULL;
                                $parentType = NULL;
                                if (!empty($direction['bean_id'])) {
                                    logLine("Bean Id already set by callListener to: " . $direction['bean_id'] . "\n");
                                    $beanID = $direction['bean_id'];
                                    $beanType = ucfirst($direction['bean_module']);
                                    //$parentType = ucfirst($direction['bean_module']);
                                    //$parentID = $beanID;
                                } else {
                                    $beans = findSugarBeanByPhoneNumber($rawData['callerID'],true,false);
                                    if( $beans != null && count($beans) == 1 ) {
                                        $beanID = $beans[0]['bean_id'];
                                        $beanType = $beans[0]['bean_module'];
                                        $parentType = $beans[0]['parent_module'];
                                        $parentID = $beans[0]['parent_id'];
                                    }
                                }
                                setRelationshipBetweenCallAndBean($callRecord['sweet']['id'], $beanType, $beanID);
                                // This might not be needed... forget if service does this already.
                                if ($beanType == "Accounts") {
                                    $parentType = "Accounts";
                                    $parentID = $beanID;
                                    //changing parent id to accounts instead of what was set in relationship between above
                                    logLine(" Setting relationship to Accounts instead of the bean in setRelationshipBetweenCallAndBean" . "\n");
                                }

                                //var_dump($parentType);
                                //var_dump($parentID);
                                logLine(" Call start was " . gmdate('Y-m-d H:i:s', $callStart) . "\n");

                                //
                                // ... on success also update entry in Calls module
                                //
                                logLine(" [$id] (OUTBOUND) Now updating record in /Calls/ id=" . $callRecord['sweet']['id'] . "...\n");

                                //print_r($callRecord);
                                logLine("NAME: " . $callRecord['sweet']['name'] . "\n");
                                logLine("DESCRIPTION: " . $callRecord['sweet']['description'] . "\n");

                                gitimg_log("call-out");

                                $dateStart = gmdate('Y-m-d H:i:s', $callStart);
                                $asteriskCallIDC = empty($rawData['asterisk_id2']) ? $rawData['asterisk_id'] : $rawData['asterisk_id2'];
                                $dUserExtension = $direction['user_extension'];
                                $dInboundExtension = $direction['inbound_extension'];
                                $crSweetID = $callRecord['sweet']['id'];

                                if( $sugar_config['asterisk_logger_sql_mode'] ) {
                                    $query = "UPDATE calls, calls_cstm
                                              SET name='{$callName}',
                                              duration_hours='{$callDurationHours}',
                                              duration_minutes='{$callDurationMinutes}',
                                              status='{$callStatus}',
                                              description='{$callDescription}',
                                              asterisk_caller_id_c='{$rawData['callerID']}',
                                              asterisk_call_id_c='{$asteriskCallIDC}',
                                              asterisk_user_ext_c='{$dUserExtension}',
                                              asterisk_inbound_ext_c='{$dInboundExtension}',
                                              date_start='{$dateStart}'
                                              parent_type='{$parentType}',
                                              parent_id='{$parentID}',
                                              assigned_user_id='{$assignedUser}'
                                              WHERE calls.id = '{$crSweetID}'
                                              AND calls_cstm.id_c = '{$crSweetID}'";
                                    db_checked_query($query);
                                }
                                else {
                                    $soapResult = $soapClient->call('set_entry', array(
                                        'session' => $soapSessionId,
                                        'module_name' => 'Calls',
                                        'name_value_list' => array(
                                            array(
                                                'name' => 'id',
                                                'value' => $callRecord['sweet']['id']
                                            ),
                                            array(
                                                'name' => 'name',
                                                'value' => $callName
                                            ),
                                            array(
                                                'name' => 'duration_hours',
                                                'value' => $callDurationHours
                                            ),
                                            array(
                                                'name' => 'duration_minutes',
                                                'value' => $callDurationMinutes
                                            ),
                                            array(
                                                'name' => 'status',
                                                'value' => $callStatus
                                            ),
                                            array(
                                                'name' => 'description',
                                                'value' => $callDescription
                                            ),
                                            array(
                                                'name' => 'asterisk_caller_id_c',
                                                'value' => $rawData['callerID']
                                            ),
                                            array(
                                                'name' => 'asterisk_call_id_c',
                                                'value' => empty($rawData['asterisk_id2']) ? $rawData['asterisk_id'] : $rawData['asterisk_id2']
                                            ),
                                            array(
                                                'name' => 'asterisk_user_ext_c',
                                                'value' => $direction['user_extension']
                                            ),

                                            array(
                                                'name' => 'asterisk_inbound_ext_c',
                                                'value' => $direction['inbound_extension']
                                            ),

                                            array(
                                                'name' => 'date_start',
                                                'value' => gmdate('Y-m-d H:i:s', $callStart)
                                            ),
                                            array(
                                                'name' => 'parent_type',
                                                'value' => $parentType
                                            ),
                                            array(
                                                'name' => 'parent_id',
                                                'value' => $parentID
                                            ),
                                            array(
                                                'name' => 'assigned_user_id',
                                                'value' => $assignedUser
                                            )
                                        )
                                    ));
                                }
                            }
                        } else {
                            logLine("[$id] FAILED TO FIND A CALL (note: there are two hangups per call, so this might not be an error)\n");
                        }
                    } else {
                        //-----------------[ INBOUND HANGUP HANDLING ]----------------------

                        $id = AMI_getUniqueIdFromEvent($e);
                        //
                        // Fetch associated call record
                        //
                        //$callRecord = findCallByAsteriskDestId($id);
                        $callRecord = findCallByAsteriskId($id);

                        if ($callRecord) {

                            //
                            // update entry in asterisk_log...
                            //
                            $rawData = $callRecord['bitter']; // raw data from asterisk_log
                            // 2013 - march removed hangup_cause=%d, hangup_cause_txt='%s'
                            $query = sprintf("UPDATE asterisk_log SET callstate='%s', timestamp_hangup=%s, answered='%s' WHERE asterisk_id='%s'", //asterisk_dest_id was asterisk_id
                                'Hangup', 'FROM_UNIXTIME(' . time() . ')', was_call_answered($id), $id);
                            dev_logString("Hungup Inbound $id");
                            $updateResult = db_checked_query($query);
                            if ($updateResult) {
                                $assignedUser = findUserIdFromChannel($rawData['channel']);

                                //
                                // ... on success also update entry in Calls module
                                //
                                //
                                // Calculate call duration...
                                //
                                $failedCall = FALSE;
                                $hangupTime = time();
                                $callDurationRaw = 0; // call duration in seconds, only matters if timestamp_link != NULL
                                if ($rawData['timestamp_link'] != NULL) {
                                    $callStartLink = strtotime($rawData['timestamp_link']);
                                    $callDurationRaw = $hangupTime - $callStartLink;
                                } else {
                                    $failedCall = TRUE;
                                }
                                $callStart = strtotime($rawData['timestamp_call']);

                                logLine(" Measured call duration is $callDurationRaw seconds\n");

                                // Recalculate call direction in minutes
                                $callDuration = (int) ($callDurationRaw / 60);
                                $callDurationHours = (int) ($callDuration / 60);
                                //$callDurationMinutes = ceil($callDuration / 60); //voor afronden naar boven.
                                $callDurationMinutes = ($callDuration % 60);

                                //
                                // Calculate final call state
                                //
                                $callStatus = NULL;
                                $callName = NULL;
                                $callDescription = "";
                                if (!$failedCall) {
                                    $callStatus = 'Held';
                                    $callName = $mod_strings['YAAI']['ASTERISKLBL_COMING_IN'];

                                    // This means call description was updated through AJAX so lets not overwrite the subject/description already assigned to the call.
                                    if (!empty($callRecord['sweet']['description'])) {
                                        $callName = $callRecord['sweet']['name'];
                                        $callDescription = $callRecord['sweet']['description'];
                                    }
                                } else {
                                    $callStatus = $sugar_config['asterisk_short_call_status']; // User gets to choose if they should be Missed or Held, if Missed then it leaves an open activity which has to be closed.
                                    $callName = $mod_strings['YAAI']['CALL_NAME_MISSED'];
                                    $callDescription = "{$mod_strings['YAAI']['CALL_DESCRIPTION_MISSED']} ({$e['Cause-txt']}\n";
                                    $callDescription .= "------------------\n";
                                    $callDescription .= sprintf(" %-20s : %-40s\n", $mod_strings['YAAI']['CALL_DESCRIPTION_PHONE_NUMBER'], $rawData['callerID']);
                                    if ($rawData['opencnam']) {
                                        $callName .= " - " . $rawData['opencnam'];
                                        $callDescription .= sprintf(" %-20s : %-40s\n", $mod_strings['YAAI']['CALL_DESCRIPTION_CALLER_ID'], $rawData['opencnam']);
                                    }

                                    logLine(" Adding INBOUND Missed (or Failed) Call, id=$id, call_id = " . $callRecord['sweet']['id'] . "\n");
                                    dev_logString(" Adding INBOUND Missed (or Failed) Call, id=$id, call_id = " . $callRecord['sweet']['id'] . "\n");
                                }

                                // Establish Relationships with the Call and Contact/Account
                                $beanID = NULL;
                                $beanType = NULL;
                                $parentID = NULL;
                                $parentType = NULL;
                                if (!empty($direction['bean_id'])) {
                                    logLine("Bean Id already set by callListener to: " . $direction['bean_id'] . "\n");
                                    $beanID = $direction['bean_id'];
                                    $beanType = ucfirst($direction['bean_module']);
                                } else {
                                    $beans = findSugarBeanByPhoneNumber($rawData['callerID'],true,false);
                                    if( $beans != null && count($beans) == 1 ) {
                                        $beanID = $beans[0]['bean_id'];
                                        $beanType = $beans[0]['bean_module'];
                                        $parentType = $beans[0]['parent_module'];
                                        $parentID = $beans[0]['parent_id'];
                                    }
                                }
                                setRelationshipBetweenCallAndBean($callRecord['sweet']['id'], $beanType, $beanID);
                                // This might not be needed... forget if service does this already.
                                if ($beanType == "Accounts") {
                                    $parentType = "Accounts";
                                    $parentID = $beanID;
                                }

                                echo ("! Call start was " . gmdate('Y-m-d H:i:s', $callStart) . "\n");

                                //
                                // ... on success also update entry in Calls module
                                //
                                logLine(" Updating record in /Calls/ id=" . $callRecord['sweet']['id'] . "...\n");

                                //print_r($callRecord);
                                logLine(" NAME: " . $callRecord['sweet']['name'] . "\n");
                                logLine(" DESCRIPTION: " . $callRecord['sweet']['description'] . "\n");

                                gitimg_log("call-in");


                                if( $sugar_config['asterisk_logger_sql_mode'] ) {
                                    $query = "UPDATE calls, calls_cstm
                                              SET name='{$callName}',
                                              duration_hours='{$callDurationHours}',
                                              duration_minutes='{$callDurationMinutes}',
                                              status='{$callStatus}',
                                              description='{$callDescription}',
                                              asterisk_caller_id_c='{$rawData['callerID']}',
                                              asterisk_call_id_c='{$rawData['asterisk_id']}',
                                              asterisk_user_ext_c='{$direction['user_extension']}',
                                              asterisk_inbound_ext_c='{$direction['inbound_extension']}',
                                              parent_type='{$parentType}',
                                              parent_id='{$parentID}',
                                              assigned_user_id='{$assignedUser}'
                                              WHERE calls.id = '{$callRecord['sweet']['id']}'
                                              AND calls_cstm.id_c = '{$callRecord['sweet']['id']}'";
                                    db_checked_query($query);
                                }
                                else {
                                    $soapResult = $soapClient->call('set_entry', array(
                                        'session' => $soapSessionId,
                                        'module_name' => 'Calls',
                                        'name_value_list' => array(
                                            array(
                                                'name' => 'id',
                                                'value' => $callRecord['sweet']['id']
                                            ),
                                            array(
                                                'name' => 'name',
                                                'value' => $callName
                                            ),
                                            array(
                                                'name' => 'duration_hours',
                                                'value' => $callDurationHours
                                            ),
                                            array(
                                                'name' => 'duration_minutes',
                                                'value' => $callDurationMinutes
                                            ),
                                            array(
                                                'name' => 'status',
                                                'value' => $callStatus
                                            ),
                                            array(
                                                'name' => 'description',
                                                'value' => $callDescription
                                            ),
                                            array(
                                                'name' => 'asterisk_caller_id_c',
                                                'value' => $rawData['callerID']
                                            ),
                                            array(
                                                'name' => 'asterisk_call_id_c',
                                                'value' => $rawData['asterisk_id']
                                            ),
                                            array(
                                                'name' => 'asterisk_user_ext_c',
                                                'value' => $direction['user_extension']
                                            ),

                                            array(
                                                'name' => 'asterisk_inbound_ext_c',
                                                'value' => $direction['inbound_extension']
                                            ),
                                            array(
                                                'name' => 'date_start',
                                                'value' => gmdate('Y-m-d H:i:s', $callStart)
                                            ),
                                            array(
                                                'name' => 'parent_type',
                                                'value' => $parentType
                                            ),
                                            array(
                                                'name' => 'parent_id',
                                                'value' => $parentID
                                            ),
                                            array(
                                                'name' => 'assigned_user_id',
                                                'value' => $assignedUser
                                            )
                                        )
                                    ));
                                }

                            } // End Inbound Case
                            // In case of multiple extensions when a call is not answered, every extensions produces a failed call record,
                            // this will keep the first of those records but delete the rest. (LIMIT 1,999999999999 in query returns all but first match.)
                            $query = "SELECT asterisk_id FROM asterisk_log WHERE asterisk_id='$id'";
                            $result = db_checked_query($query);
                            $result_id = db_fetchAssoc($result);
                            logLine("Cleaning up Failed Calls part1, asterisk_id = " . $result_id['asterisk_id'] . "\n");

                            $query = "SELECT call_record_id FROM asterisk_log WHERE asterisk_id='" . $result_id['asterisk_id'] . "' ORDER BY id ASC";
                            $result = $GLOBALS['db']->limitQuery($query,1,99999);

                            while ($call_record_id = db_fetchAssoc($result)) {
                                $query = "DELETE FROM calls WHERE id='" . $call_record_id['call_record_id'] . "' AND name LIKE '{$mod_strings['YAAI']['CALL_NAME_MISSED']}%'";
                                $rq = db_checked_query($query);

                                if ($GLOBALS['db']->getAffectedRowCount($rq) > 0) {
                                    logLine("Cleaning up Failed Calls part2, DELETED call_record_id = {$call_record_id['call_record_id']}\n");
                                    // TODO Change this to Delete Call
                                    $query = "DELETE FROM calls_cstm WHERE id_c='{$call_record_id['call_record_id']}'";
                                    dev_logString("Deleting Call Rec: " . $call_record_id['call_record_id'] );

                                    db_checked_query($query);
                                }
                                //$total_result = db_fetchAssoc($rq);
                                //var_dump($total_result);
                            }
                        } // End if callRecordId
                    } // End if INBOUND hangup event
                }// End of HangupEvent.
                // success
                //Asterisk Manager 1.1
                if ($e['Event'] == 'Bridge') {
                    logLine("DEBUG: Entered Bridge");
                    $query = "SELECT direction, callstate FROM asterisk_log WHERE asterisk_id='" . $e['Uniqueid2'] . "' OR asterisk_dest_id='" . $e['Uniqueid2'] . "'" . " OR asterisk_id='" . $e['Uniqueid1'] . "' OR asterisk_dest_id='" . $e['Uniqueid1'] . "'"; // Second half of this is for outgoing
                    $result = db_checked_query($query);
                    $direction = db_fetchAssoc($result);
                    if( $direction['callstate'] != "Connected" ) {
                        if ($direction['direction'] == "I") {
                            $callDirection = "Inbound";
                            logLine("DEBUG: bridge inbound, updating the Link state");
                            // Inbound bridge event
                            $query = "UPDATE asterisk_log SET callstate='Connected', timestamp_link=FROM_UNIXTIME(" . time() . ") WHERE asterisk_dest_id='" . $e['Uniqueid1'] . "' OR asterisk_dest_id='" . $e['Uniqueid2'] . "'";
                            dev_logString("Set callState = Connected IBC");
                            $rc = db_checked_query($query);

                            // Delete all the extra inbound records
                            $id1 = $e['Uniqueid1'];
                            $id2 = $e['Uniqueid2'];
                            $query = "SELECT call_record_id FROM asterisk_log WHERE asterisk_id='" . $id1 . "' AND asterisk_dest_id!='" . $id2 . "' and callstate != 'Connected'"; // asterisk_dest_id part is for ring groups only i think...
                            $result = db_checked_query($query);
                            while ($call_rec_id = db_fetchAssoc($result)) {
                                logLine("Deleting Call Record: " . $call_rec_id['call_record_id']);
                                deleteCall($call_rec_id['call_record_id']);
                            }
                        } else if($direction['direction'] == "O") {
                            $query = "UPDATE asterisk_log SET callstate='Connected', timestamp_link=FROM_UNIXTIME(" . time() . ") WHERE asterisk_id='" . $e['Uniqueid1'] . "' OR asterisk_id='" . $e['Uniqueid2'] . "'";
                            dev_logString("Set callState = Connected OBC");
                            $rc = db_checked_query($query);
                        }
                    }

                    // Here we add support for complicated Ring Groups such as x1 ---> 615 ---> 710,722,735
                    // \--> 620 ---> 810,811,812
                    // Check if both channels are internal... Then, check the asterisk_log table to see if an entry exists where Channel matches one of them... if so then change it out.
                    if (isCallInternal($e['Channel1'], $e['Channel2'])) {
                        logLine("Internal Bridge Event Detected\n");
                        if (preg_match('/(.*);(.*)/', $e['Channel1'], $matches)) {
                            $chanToFind = $matches[1] . '%';
                            $query = "SELECT id FROM asterisk_log WHERE channel like '$chanToFind' and direction='I' ";
                            logLine("Internal: $query\n");
                            $result = db_checked_query($query);
                            // TODO clean up all these logLines.
                            if ($GLOBALS['db']->getRowCount($result) > 1) {
                                logLine("RG-Bridge ERROR: MULTIPLE MATCHING LINES IN ASTERISK LOG... BRIDGE LOGIC ISN'T BULLETPROOF\n");  // NOTE: I'm not aware of this line ever occurring... so maybe it is bulletproof.
                            } else if ($GLOBALS['db']->getRowCount($result) == 1) {
                                logLine(" RG-Bridge Detected changing the channel to: {$e['Channel2']}\n");
                                $result_id = db_fetchAssoc($result);
                                $chan2 = $e['Channel2'];
                                $theId = $result_id['id'];
                                $userExtension = extractExtensionNumberFromChannel($chan2);
                                $query = "UPDATE asterisk_log SET channel='$chan2', user_extension='$userExtension' WHERE id='$theId'";
                                dev_logString("RG Bridge Set Channel of $theId to $chan2");
                                logLine("UPDATE QUERY: $query\n");
                                db_checked_query($query);
                            } else {
                                logLine("DEBUG: RG-BRIDGE ROWS found");
                            }
                        } else {
                            logLine("RG-Bridge didn't match regex.\n");
                        }
                    } else {
                        logLine("Bridge isn't internal to internal");

                        // This could be a call queue event...
                        //--
                        logLine("Internal Queue Event Detected\n");
                        // if( preg_match('/(.*);(.*)/',$e['Channel1'],$matches) ) {
                        $chanToFind = $e['Channel1'];
                        $query = "SELECT id FROM asterisk_log WHERE remote_channel like '$chanToFind' and direction='I' and (channel = '' OR channel is NULL) ";
                        logLine("Internal Queue: $query\n");
                        $result = db_checked_query($query);
                        // TODO clean up all these logLines.
                        if ($GLOBALS['db']->getRowCount($result) > 1) {
                            logLine("Queue-Bridge ERROR: MULTIPLE MATCHING LINES IN ASTERISK LOG... BRIDGE LOGIC ISN'T BULLETPROOF\n");
                        } else if ($GLOBALS['db']->getRowCount($result) == 1) {
                            logLine(" Queue-Bridge Detected changing the channel to: {$e['Channel2']}\n");
                            $result_id = db_fetchAssoc($result);
                            $chan2 = $e['Channel2'];
                            $user_device = extractUserDeviceFromChannel($e['Channel2']);
                            $theId = $result_id['id'];
                            $userExtension = extractExtensionNumberFromChannel($chan2);
                            $query = "UPDATE asterisk_log SET channel='$chan2', user_extension='$userExtension', user_device='$user_device' WHERE id='$theId'";
                            dev_logString("Queue-Bridge Set Channel to $chan2 for $theId\n");
                            logLine("Queue UPDATE QUERY: $query\n");
                            db_checked_query($query);
                        } else {
                            logLine("DEBUG: NO Queue BRIDGE ROWS found");
                        }
                        //}
                        //else {
                        // logLine("Queue-Bridge didn't match regex.\n");
                        //}
                    }
                }

                //Asterisk Manager 1.0 (Only)... This is the equivalent of bridge
                if($e['Event'] == 'Link')
                {
                    $query = "UPDATE asterisk_log SET callstate='Connected', timestamp_link=FROM_UNIXTIME(".time().") WHERE asterisk_id='" . $e['Uniqueid1'] . "' OR asterisk_id='" . $e['Uniqueid2'] . "'";
                    $rc = db_checked_query($query);
                    // NOTE: AMI v1.0 will not support Ring Groups and Queues like AMI v1.1 does until it's ported.
                };

                // Reset event buffer
                $event = '';
            }
        }

        // handle partial packets
        if ($event_started) {
            $event .= $buffer;
        } else if (strstr($buffer, 'Event:')) {
            $event = $buffer;
            $event_started = true;
        }


        if($log_memory_usage)
        {
            $diff = time() - $last_memory_log_entry;
            if( $diff > $memory_usage_log_frequency_secs ) {
                logLine( getTimestamp() . "," . memory_get_usage(), $memory_usage_log_file);
                $last_memory_log_entry = time();
            }
        }

        // for if the connection to the sql database gives out.
        // TODO Find a better way to check the connection. I think on Shared Hosting Servers mysql_ping might be disabled which causes this to always reconnect.
//        if (!mysql_ping($sql_connection)) {
//            //here is the major trick, you have to close the connection (even though its not currently working) for it to recreate properly.
//            mysql_close($sql_connection);
//            logLine("__MySQL connection lost, reconnecting__\n");
//            $sql_connection = mysql_connect($sugar_config['dbconfig']['db_host_name'], $sugar_config['dbconfig']['db_user_name'], $sugar_config['dbconfig']['db_password']);
//            $sql_db = mysql_select_db($sugar_config['dbconfig']['db_name']);
//            db_checked_query("SET time_zone='+00:00'");
//        }
    }

    logLine(getTimestamp() . "Event loop terminated, attempting to login again\n");
    sleep(1);
}

// TODO i've seen asteriskLogger crash due to a script executing too long error... It was on a pretty budget shared hosting server.
// Hasn't ever happened to me personally... but still something noteworthy.

exit(0);

