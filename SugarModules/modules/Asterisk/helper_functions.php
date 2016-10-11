<?php

// ******************
// Helper functions *
// ******************

function createCallId(){
    $query = "SELECT UUID() AS id";
    $result = db_checked_query($query);
    $row = $GLOBALS['db']->fetchByAssoc($result);
    return $row['id'];
}

function findParentDetailsByBean($beanId, $module)
{
    if ($module === 'Leads') {
        $query = "SELECT CONCAT_WS(' ', first_name, last_name) AS recordName, name AS accountName, l.description AS beanDescription, a.description AS accountDescription, department, title
                  FROM leads as l
                  LEFT JOIN accounts AS a ON l.account_id = a.id
                  WHERE l.id = '{$beanId}'";
    }

    if ($module === 'Contacts') {
        $query = "SELECT CONCAT_WS(' ', first_name, last_name) AS recordName, name AS accountName, c.description AS beanDescription, a.description AS accountDescription, department, title
                  FROM contacts AS c
                  LEFT JOIN accounts_contacts AS ac ON c.id = ac.contact_id
                  LEFT JOIN accounts AS a ON ac.account_id = a.id
                  WHERE c.id = '{$beanId}'";
    }

    if (!$query) {
        logLine("findContactDetailsByBean was passed a module value that is not supported.  No data will be returned");
        return FALSE;
    }
    $queryResult = db_checked_query($query);
    if (!$queryResult) {
        logLine("! Contact lookup failed in findParentDetailsByBean");
        return FALSE;
    }

    return $GLOBALS['db']->fetchByAssoc($queryResult);
}

function queryEmailAddressBySugarId($id)
{
    $emailSql = "SELECT email_addresses.email_address
                 FROM email_addr_bean_rel
                 LEFT JOIN email_addresses ON email_addr_bean_rel.email_address_id = email_addresses.id
                 WHERE email_addr_bean_rel.bean_id = '{$id}'";
    logLine("Email SQL: " . $emailSql);
    $queryEmailResult = db_checked_query($emailSql);
    $emailRow = $GLOBALS['db']->fetchByAssoc($queryEmailResult);
    return $emailRow['email_address'];
}

/**
 * Removes calls from asterisk_log that have expired or closed.
 * 1) Call Popup Closed Manually by user in sugar.
 * 2) Call has been hungup for at least an hour
 * 3) Call was created over 5 hours ago (this is just in case of bugs where hangup isn't set for some reason).
 */
function purgeExpiredEventsFromDb() {
    global $sugar_config;
    $popupsExpireMins = 60;
    if( !empty( $sugar_config['asterisk_hide_call_popups_after_mins'] ) ) {
        $popupsExpireMins = $sugar_config['asterisk_hide_call_popups_after_mins'];
    }

    $calls_expire_time = date('Y-m-d H:i:s', time() - ($popupsExpireMins * 60) );
    $five_hours_ago = date('Y-m-d H:i:s', time() - 5 * 60 * 60);

    // BR: 2013-04-30 fixed bug where closing the call popup before the call was over the duration would potentially not get set right.
    $query = "DELETE FROM asterisk_log WHERE (uistate = 'Closed' AND timestamp_hangup is not NULL) OR ( timestamp_hangup is not NULL AND '$calls_expire_time' > timestamp_hangup ) OR ('$five_hours_ago' > timestamp_call )";
    //logLine( "Debug Purge Query: " . $query );

    $result = db_checked_query($query);
    $rowsDeleted = $GLOBALS['db']->getAffectedRowCount($result);
    if( $rowsDeleted > 0 ) {
        logLine("  Purged $rowsDeleted row(s) from the call log table.");
    }
}

function isCallInternal($chan1, $chan2) {
    global $asteriskMatchInternal;
    return (preg_match($asteriskMatchInternal, $chan1) && preg_match($asteriskMatchInternal, $chan2));
}

// go through and parse the event
function getEvent($event) {
    $e = array();
    $e['Event'] = '';

    $event_params = explode("\n", $event);

    foreach ($event_params as $event) {
        if (strpos($event, ": ") > 0) {
            list($key, $val) = explode(": ", $event);
            // $values = explode(": ", $event);
            $key = trim($key);
            $val = trim($val);

            if ($key) {
                $e[$key] = $val;
            }
        }
    }
    return ($e);
}

function getTimestamp() {
    return date('[Y-m-d H:i:s]');
}

function dumpEvent(&$event) {
    // Skip 'Newexten' events - there just toooo many of 'em || For Asterisk manager 1.1 i choose to ignore another stack of events cause the log is populated with useless events
    $eventType = $event['Event'];

    // Not surpressing new channel
    // $eventType == 'Newchannel' ||
    if ($eventType == 'Newexten' || $eventType == 'UserEvent' || $eventType == 'AGIExec' ||  $eventType == 'Newstate' || $eventType == 'ExtensionStatus') {
        logLine("! AMI Event '" . $eventType . " suppressed.\n");

//        if( $eventType == 'Newexten') {
//            dumpEventHelper($event, "c:/newexten.log");
//        }
        return;
    }

    switch($eventType) {
        case "Dial":    dev_DialPrinter($event); break;
        case "Bridge":  dev_BridgePrinter($event); break;
        case "Join":    dev_JoinPrinter($event); break;
        case "Hangup":  dev_HangupPrinter($event); break;
        case "Newchannel": dev_NewChannelPrinter($event); break;
    }

    dumpEventHelper($event);
}

function dumpEventHelper(&$event, $logFile = "default" ) {
    logLine(getTimeStamp() . "\n", $logFile);
    logLine("! --- Event -----------------------------------------------------------\n",$logFile);
    foreach ($event as $eventKey => $eventValue) {
        logLine(sprintf("! %20s --> %-50s\n", $eventKey, $eventValue),$logFile);
    }
    logLine("! ---------------------------------------------------------------------\n", $logFile);
}

function getIfSet($e, $key, $default='') {
    if( isset($e[$key]) ) {
        return $e[$key];
    }
    return $default;
}

function dev_DialPrinter(&$e) {
    dev_GenericEventPrinter("Dial", getIfSet( $e, 'SubEvent'), getIfSet( $e, 'UniqueID'), getIfSet( $e, 'DestUniqueID'), getIfSet( $e, 'Channel'), getIfSet( $e, 'Destination'), getIfSet( $e, 'CallerIDNum'), getIfSet( $e, 'DialString'));
}

function dev_BridgePrinter(&$e) {
    dev_GenericEventPrinter("Bridge", getIfSet( $e, 'Bridgestate'), getIfSet( $e, 'Uniqueid1'), getIfSet( $e, 'Uniqueid2'), getIfSet( $e, 'Channel1'), getIfSet( $e, 'Channel2'), getIfSet( $e, 'CallerID1'), getIfSet( $e, 'CallerID2'));
}

function dev_JoinPrinter(&$e) {
    dev_GenericEventPrinter("Join", getIfSet( $e, 'Position'), getIfSet( $e, 'Uniqueid'), "--", getIfSet( $e, 'Channel'), "--", $e["CallerIDNum"], getIfSet( $e, 'Queue'));
}

function dev_HangupPrinter(&$e) {
    dev_GenericEventPrinter("Hangup", getIfSet( $e, 'Cause'), getIfSet( $e, 'Uniqueid'), '--', getIfSet( $e, 'Channel'), '--', $e["CallerIDNum"], getIfSet( $e, 'ConnectedLineNum'));
}

function dev_NewChannelPrinter(&$e) {
    dev_GenericEventPrinter("NewChan", getIfSet( $e, 'ChannelStateDesc'), getIfSet( $e, 'Uniqueid'), '--', getIfSet( $e, 'Channel'), '--', $e["CallerIDNum"], getIfSet( $e, 'Exten'));
}

function dev_logString($str) {
    global $dial_events_log;
    logLine( $str, $dial_events_log);
}

function dev_clearDialEventsLog() {
    global $dial_events_log;
    $fp = fopen($dial_events_log, 'w');
    $theHtml = <<<HTML_HEAD
<html><head></head><body>
<div style="font-family: monospaced;">

HTML_HEAD;
    fclose($fp);
    logLine("  Cleared the log: " . $dial_events_log);
}



function dev_GenericEventPrinter($arg1, $arg2, $arg3, $arg4, $arg5, $arg6, $arg7, $arg8) {
    global $dial_events_log;
    if( !empty($dial_events_log) ) {
        $s = getTimeStamp() . " ";
        $s .= str_pad($arg1, 8, " ", STR_PAD_BOTH);
        $s .= str_pad($arg2, 6, " ", STR_PAD_BOTH);
        $s .= str_pad($arg3, 16, " ", STR_PAD_BOTH);
        $s .= str_pad($arg4, 16, " ", STR_PAD_BOTH);
        $s .= str_pad($arg5, 55, " ", STR_PAD_BOTH);
        $s .= str_pad($arg6, 55, " ", STR_PAD_BOTH);
        $s .= str_pad($arg7, 17, " ", STR_PAD_BOTH);
        $s .= str_pad($arg8, 20, " ", STR_PAD_BOTH);
        logLine( $s, $dial_events_log . ".txt");

        $s = '<div style="font-size:90%;white-space:nowrap;"><span style="font-family:monospace;">' . date('[H:i:s]') . "</span>";

        $s .= colorize(str_pad($arg1, 8, "_", STR_PAD_BOTH));
        $s .= colorize(str_pad($arg2, 6, "_", STR_PAD_BOTH));
        $s .= colorize(str_pad($arg3, 16, "_", STR_PAD_BOTH));
        $s .= colorize(str_pad($arg4, 16, "_", STR_PAD_BOTH));
        $s .= colorize(str_pad($arg5, 55, "_", STR_PAD_BOTH));
        $s .= colorize(str_pad($arg6, 55, "_", STR_PAD_BOTH));
        $s .= colorize(str_pad($arg7, 17, "_", STR_PAD_BOTH));
        $s .= colorize(str_pad($arg8, 20, "_", STR_PAD_BOTH));
        $s = preg_replace("/_/", "&nbsp;", $s);
        $s =
            logLine( $s ."</div>", $dial_events_log);
    }

}

/**
 * Takes a string, does a md5 hash of it to get a random color, then sets background to white or black to enhance readability.
 * @param $str
 * @return string
 */
function colorize($str) {
    $hash = md5($str);
    $forecolor = substr($hash,0,6);
    $backcolor = "FFFFFF";
    if( hexdec($forecolor) > hexdec("CCCCCC") ) {
        //$backcolor = "000000";
    }

    return "<span style=\"font-family:monospace;color: #$forecolor;background-color:$backcolor\">$str</span>";
}



/**
 * Removes a call record from the sugarcrm as well as asterisk_log table.
 * @param $callRecordId - Call Record ID. Note: param is assumed to be sanitized.
 */
function deleteCall($callRecordId) {
    // NOTE: there is one other place in this file that Deletes a call, so if this code is ever refactored
    // to use SOAP, be sure to refactor that one.
    $query = "DELETE FROM calls WHERE id='$callRecordId'";
    $rowsAffected = db_checked_query_returns_affected_rows_count($query);
    if( $rowsAffected > 0 ) {
        dev_logString("Deleted " . $rowsAffected . " rows from calls");
        $query = "DELETE FROM calls_cstm WHERE id_c='$callRecordId'";
        db_checked_query($query);
    }

    $query = "DELETE FROM asterisk_log WHERE call_record_id='$callRecordId'";
    $rowsAffected = db_checked_query_returns_affected_rows_count($query);
    if( $rowsAffected > 0 ) {
        dev_logString("Deleted " . $rowsAffected . " rows from asterisk_log");
    }
}

//
// Locate whether call exists in asterisk_log database
//

function findLoggedCallByAsteriskId($asteriskId){
    $query = "SELECT id FROM asterisk_log WHERE asterisk_id='{$asteriskId}' OR asterisk_dest_id='{$asteriskId}'";
    $results = db_checked_query($query);
    $numRows = $GLOBALS['db']->getRowCount($results);
    if($numRows > 0){
        return TRUE;
    }else{
        return FALSE;
    }
}


//
// Locate associated record in "Calls" module
//
function findCallByAsteriskId($asteriskId) {
    global $soapClient, $soapSessionId;
    logLine("# +++ findCallByAsteriskId($asteriskId)\n");

    //
    // First, fetch row in asterisk_log...
    //

    $sql = sprintf("SELECT * FROM asterisk_log WHERE asterisk_id='$asteriskId'", $asteriskId);
    $queryResult = db_checked_query($sql);
    if ($queryResult === FALSE) {
        logLine("Asterisk ID NOT FOUND in asterisk_log (db query returned FALSE)");
        return FALSE;
    }

    while ($row = $GLOBALS['db']->fetchByAssoc($queryResult)) {
        $callRecId = $row['call_record_id'];
        logLine("! Found entry in asterisk_log recordId=$callRecId\n");

        //
        // ... then locate Object in Calls module:
        //
        $soapResult = $soapClient->call('get_entry', array(
            'session' => $soapSessionId,
            'module_name' => 'Calls',
            'id' => $callRecId
        ));
        $resultDecoded = decode_name_value_list($soapResult['entry_list'][0]['name_value_list']);
        // echo ("# ** Soap call successfull, dumping result ******************************\n");
        // var_dump($soapResult);
        // var_dump($resultDecoded);
        // var_dump($row);
        // echo ("# ***********************************************************************\n");
        //
        // also store raw sql data in case we need it later...
        //
        return array(
            'bitter' => $row,
            'sweet' => $resultDecoded
        );
    }
    logLine("! Warning, results set was empty!\n");
    return FALSE;
}

// AsteriskManager 1.1 for inbound calling
function findCallByAsteriskDestId($asteriskDestId) {
    global $soapClient, $soapSessionId, $verbose_logging;
    logLine("# +++ findCallByAsteriskDestId($asteriskDestId)\n");

    //
    // First, fetch row in asterisk_log...
    //

    $sql = sprintf("SELECT * from asterisk_log WHERE asterisk_dest_id='$asteriskDestId'", $asteriskDestId);
    $queryResult = db_checked_query($sql);
    if ($queryResult === FALSE) {
        return FALSE;
    }

    while ($row = $GLOBALS['db']->fetchByAssoc($queryResult)) {
        $callRecId = $row['call_record_id'];
        logLine("! FindCallByAsteriskDestId - Found entry in asterisk_log recordId=$callRecId\n");

        //
        // ... then locate Object in Calls module:
        //
        $soapResult = $soapClient->call('get_entry', array(
            'session' => $soapSessionId,
            'module_name' => 'Calls',
            'id' => $callRecId
        ));
        $resultDecoded = decode_name_value_list($soapResult['entry_list'][0]['name_value_list']);

// echo ("# ** Soap call successfull, dumping result ******************************\n");
        // var_dump($soapResult);
        if ($verbose_logging) {
            var_dump($resultDecoded);
        }
        // var_dump($row);
        // echo ("# ***********************************************************************\n");
        //
        // also store raw sql data in case we need it later...
        //
        return array(
            'bitter' => $row,
            'sweet' => $resultDecoded
        );
    }
    logLine("! Warning, FindCallByAsteriskDestId results s
    et was empty!\n");
    return FALSE;
}

//
// Repacks a name_value_list eg returned by get_entry() into a hash (aka associative array in PHP speak)
//
function decode_name_value_list(&$nvl) {
    $result = array();

    foreach ($nvl as $nvlEntry) {
        $key = $nvlEntry['name'];
        $val = $nvlEntry['value'];
        $result[$key] = $val;
    }
    return $result;
}

/**
 *
 * @param $origPhoneNumber
 * @param bool $stopOnFind -Controls whether or not to keep searching down the list of modules to find a match...
 *                          For example, if a match in contacts is found... it will not try leads.
 * @param bool $returnMultipleMatches - when true it returns all matches (callinize push uses true, unattended will use false)
 *                                      think of this as attended vs. unattended mode... true it returns all the matches to the user so
 *                                      they can see all the results where false a computer has to make a decision and we dont ever want to make assumptions.
 * @return An|array|null
 */
function findSugarBeanByPhoneNumber($origPhoneNumber,$stopOnFind=false, $returnMultipleMatches=false) {
    global $sugar_config;
    require_once("include/sugar_rest.php");
    $url = $sugar_config["site_url"] . '/custom/service/callinize/rest.php';
    $sugar = new Sugar_REST($url, $sugar_config['asterisk_soapuser'], $sugar_config['asterisk_soappass']);
    $params = array();
    $params['phone_number'] = $origPhoneNumber;

    $params['stop_on_find'] = $stopOnFind;
    $beans = $sugar->custom_method("find_beans_with_phone_number", $params);

    $retVal = null;

    if (count($beans) == 1) {
        $retVal = $beans; // Do not return beans[0]!
    } else if (count($beans) > 1) {
        //below code is primarily used for mobile version
        if ($returnMultipleMatches === true) {
            $retVal = $beans;
            //below code is primarily used for desktop version
        } else {
            // Check if all beans are from the same parent
            $firstParentId = $beans[0]['parent_id'];
            $moreThanOneParent = false;
            for ($i = 1; $i < count($beans); $i++) {
                if ($beans[$i]['parent_id'] !== $firstParentId) {
                    $moreThanOneParent = true;
                    break;
                }
            }
            if (!$moreThanOneParent && !empty($firstParentId)) {
                $retVal = array();
                $retVal['bean_id'] = $beans[0]['parent_id'];
                $retVal['bean_name'] = $beans[0]['parent_name'];
                $retVal['bean_link'] = $beans[0]['parent_link'];
            }
        }
    }

    return $retVal;
}

function findCallDetailsByClickToDialString($string){
    logLine($string);
    $record = substr($string, -36);
    logLine($record);
    //example CLICKTODIAL-+14151111111-Leads-58314dd3-3ca6-2a21-94f4-517876c1d81e
    $split = explode("-", $string);
    $number = $split[1];
    $module = $split[2];
    $dbtable = strtolower($split[2]);
    $query = "SELECT * FROM {$dbtable} WHERE id='{$record}'";
    logLine($query);
    $results = db_checked_query($query);
    if($results){
        $result = db_fetchAssoc($results);
        if(!$result['name']){
            $result['name'] = "{$result['first_name']} {$result['last_name']}";
        }
        return array(
            'dbtable' => $dbtable,
            'module' => $module,
            'record' => $record,
            'number' => $number,
            'query' => $result
        );
    }
}


//
// Finds related account for given contact id
//
function findAccountForContact($aContactId) {
    global $soapClient, $soapSessionId;
    logLine("### +++ findAccountForContact($aContactId)\n");

    $soapArgs = array(
        'session' => $soapSessionId,
        'module_name' => 'Contacts',
        'module_id' => $aContactId,
        'related_module' => 'Accounts',
        'related_module_query' => '',
        'deleted' => 0
    );

    $soapResult = $soapClient->call('get_relationships', $soapArgs);

    // TODO check if error exists first to prevent Notice about index not existing in log.
    if (!isSoapResultAnError($soapResult)) {
        // var_dump($soapResult);

        $assocCount = count($soapResult['ids']);

        if ($assocCount == 0) {
            logLine(" + No associated account found\n");
            return FALSE;
        } else {
            if ($assocCount > 1) {
                logLine("! WARNING: More than one associated account found, using first one.\n");
            }
            $assoAccountID = $soapResult['ids'][0]['id'];
            logLine(" + Associated account is $assoAccountID\n");
            return $assoAccountID;
        }
    }
}

/**
 * prints soap result info.  Known ISSUE: Can't use this for get_entry method.... it doesn't return result_count
 * Returns true if results were returned, FALSE if an error or no results are returned.
 *
 * @param $soapResult
 */
function isSoapResultAnError($soapResult) {
    $retVal = FALSE;
    if (isset($soapResult['error']['number']) && $soapResult['error']['number'] != 0) {
        logLine("! ***Warning: SOAP error*** " . $soapResult['error']['number'] . " " . $soapResult['error']['string'] . "\n");
        $retVal = TRUE;
    } else if (!isset($soapResult['result_count']) || $soapResult['result_count'] == 0) {
        logLine("! No results returned\n");
        $retVal = TRUE;
    }
    return $retVal;
}

/**
 * Performs a soap call to set a relationship between a call record and a bean (contact)
 * @param $callRecordId string - the call record id.
 * @param $beanType string - usually "Contacts"
 * @param $beanId string
 */
function setRelationshipBetweenCallAndBean($callRecordId, $beanType, $beanId) {
    global $soapSessionId, $soapClient, $verbose_logging;

    if (!empty($callRecordId) && !empty($beanId) && !empty($beanType)) {
        $soapArgs = array(
            'session' => $soapSessionId,
            'set_relationship_value' => array(
                'module1' => 'Calls',
                'module1_id' => $callRecordId,
                'module2' => $beanType,
                'module2_id' => $beanId
            )
        );

        logLine(" Establishing relation to $beanType ID: $beanId, Call Record ID: $callRecordId");
        if ($verbose_logging) {
            var_dump($soapArgs);
        }
        $soapResult = $soapClient->call('set_relationship', $soapArgs);
        isSoapResultAnError($soapResult);
    } else {
        logLine("! Call is not related to any record (no matches)");
        logLine("! Invalid Arguments passed to setRelationshipBetweenCallAndBean callRecordId=$callRecordId, beanId=$beanId, beanType=$beanType\n");
    }
}

///
/// Given the channel ($rawData['channel']) from the AMI Event, this returns the user ID the call should be assigned to.
/// If a suitable user extension cannot be found, Admin is returned
///
function findUserIdFromChannel($channel) {
    global $userGUID;

    $asteriskExt = extractExtensionNumberFromChannel($channel);

    $maybeAssignedUser = findUserByAsteriskExtension($asteriskExt);
    if ($maybeAssignedUser) {
        $assignedUser = $maybeAssignedUser;
        logLine("! Assigned user id set to $assignedUser\n");
    } else {
        $assignedUser = $userGUID;
        logLine(" ! Assigned user will be set to Administrator.\n");
    }

    return $assignedUser;
}

/**
 * attempts to find the "device" which is either the extension number or remote phone number if calling an external number
 * @param $channel
 * @return mixed
 */
function extractUserDeviceFromChannel($channel) {
    if( preg_match('/Local\/(.+?)@.+/', $channel, $matches ) ){
        return $matches[1];
    }
    logLine(" !WARNING: wasn't able to extract the user device from channel : $channel");
    return $matches[0]; // If we get here we probably need to add more cases.
}

//
// Attempt to find assigned user by asterisk ext
// PRIVATE METHOD: See findUserIdFromChannel
//
function extractExtensionNumberFromChannel($channel) {
    global $sugar_config;
    $asteriskExt = FALSE;
    $channelSplit = array();
    logLine("Looking for user extension number in: $channel\n");

// KEEP THIS BLOCK OF CODE IN SYNC WITH OUTBOUND
// BR: This cases matches things like Local/LC-52@from-internal-4bbbb
    $pattern = $sugar_config['asterisk_dialin_ext_match'];
    if (!startsWith($pattern, '/')) {
        $pattern = '/' . $pattern . '/i';
    }
    if (!empty($sugar_config['asterisk_dialin_ext_match']) && preg_match($pattern, $channel, $regs)) {
        logLine("Matched User REGEX. Regex: " . $regs[1] . "\n");
        $asteriskExt = $regs[1];
    }
// This matches the standard cases such as SIP/### or IAX/###
    else if (eregi('^([[:alpha:]]+)/([[:alnum:]]+)-', $channel, $channelSplit) > 0) {
        $asteriskExt = $channelSplit[2];
        logLine("Channel Matched SIP/### style regex. Ext is:" . $asteriskExt . "\n");
    } else {
        $asteriskExt = FALSE;
    }

    return $asteriskExt;
}

//
// Locate user by asterisk extension
// NOTE: THIS RETURNS JUST AN ID
// PRIVATE METHOD: See findUserIdFromChannel
//
function findUserByAsteriskExtension($aExtension) {
    logLine("### +++ findUserByAsteriskExtension($aExtension)\n");
    // The query below is actually pretty clever. Recall that user extensions can be a comma seperated list.
    // The 4 conditions are necessary 1) To match single extension case, 2) to match first extension in the list
    // 3) to match one in the middle of list, 4) matches one at the end of a list.
    $qry = "select id,user_name from users join users_cstm on users.id = users_cstm.id_c where " .
        "(users_cstm.asterisk_ext_c='$aExtension' or users_cstm.asterisk_ext_c LIKE '$aExtension,%' " .
        "OR users_cstm.asterisk_ext_c LIKE '%,$aExtension,%' OR users_cstm.asterisk_ext_c LIKE '%,$aExtension') and status='Active'";

    $result = db_checked_query($qry);
    if ($result) {
        $row = db_fetchAssoc($result);

        // All this if statement does is detect if multiple users were returned and if so display warning.
        if ($GLOBALS['db']->getRowCount($result) > 1) {
            $firstUser = $row['user_name'];
            $usernames = $row['user_name'];
            while ($row2 = db_fetchAssoc($result)) {
                $usernames .= ", " . $row2['user_name'];
            }
            logLine("### __WARNING__ Extension $aExtension matches the following users: $usernames! Call will be assigned to: $firstUser!");
        }

        return $row['id'];
    }

    return FALSE;

///// OLD WAY OF DOING IT IS WITH SOAP... DIDN"T WORK FOR ME... so reverted to db query.
    /*
      global $soapClient, $soapSessionId;
      print("# +++ findUserByAsteriskExtension($aExtension)\n");

      //'select_fields'=>array('id', 'first_name', 'last_name'),
      //'deleted' => 0,
      $soapArgs = array(
      'session' => $soapSessionId,
      'module_name' => 'Users',
      'query' => '(users_cstm.asterisk_ext_c=\'710\')',
      // 'query' => sprintf("(users_cstm.asterisk_ext_c='%s')", $aExtension),
      'select_fields'=>array('id', 'first_name', 'last_name'),
      );
      //var_dump($soapArgs);

      $soapResult = $soapClient->call('get_entry_list', $soapArgs);

      var_dump($soapResult);

      if ($soapResult['error']['number'] != 0) {
      logLine("! Warning: SOAP error " . $soapResult['error']['number'] . " " . $soapResult['error']['string'] . "\n");
      }
      else if( $soapResult['result_count'] == 0 ) {
      logLine("! No results returned\n");
      }
      else {
      $resultDecoded = decode_name_value_list($soapResult['entry_list'][0]['name_value_list']);
      // print "--- SOAP get_entry_list() ----- RESULT --------------------------------------\n";
      var_dump($resultDecoded);
      // print "-----------------------------------------------------------------------------\n";
      return $resultDecoded['id'];
      }

      return FALSE;
     */
}

//
// Checked execution of a MySQL query
//
// This function provides a wrapper around mysql_query(), providing SQL and error loggin
//
function db_checked_query($aQuery) {
    global $db_log_queries;
    global $db_log_results;

    if ($db_log_queries || $db_log_results) {
        logLine(" +++ db_checked_query()\n");
    }

    $query = trim($aQuery);
    if ($db_log_queries) {
        logLine(" ! SQL: $query\n");
    }

    // Is this is a SELECT ?
    $isSelect = eregi("^select", $query);

    $sqlResult = $GLOBALS['db']->query($query,false);

    if ($db_log_results) {
        if (!$sqlResult) {
            // Error occured
            logLine("! SQL error " . $GLOBALS['db']->lastDbError() );
        } else {
            // SQL succeeded
            if ($isSelect) {
                logLine(" --> Rows in result set: " . $GLOBALS['db']->getRowCount($sqlResult) . "\n");
            } else {
                logLine(" --> Rows affected: " . $GLOBALS['db']->getAffectedRowCount($sqlResult) . "\n");
            }
        }

    }


    return $sqlResult;
}


function db_print_log_table() {
    logLine("---[ Asterisk Log Table ]------------");
    $res = $GLOBALS['db']->query("select * from asterisk_log", false);
    while ($row = $GLOBALS['db']->fetchByAssoc($res)) {
        logLine($row['id'] . "\n");
    }
    logLine("\n");
}

function db_fetchAssoc($results) {
    return $GLOBALS['db']->fetchByAssoc($results);
}

/**
 * Method is equivalent to calling mysql_affected_rows( db_checked_query($query));
 * @param $query
 */
function db_checked_query_returns_affected_rows_count($query){
    $result = db_checked_query($query);
    return $GLOBALS['db']->getAffectedRowCount($result);
}

// mt_get: returns the current microtime
function mt_get(){
    global $mt_time;
    list($usec, $sec) = explode(" ", microtime());
    return ((float)$usec + (float)$sec);
}

// mt_start: starts the microtime counter
function mt_start(){
    global $mt_time; $mt_time = mt_get();
}

// mt_end: calculates the elapsed time
function mt_end($len=4){
    global $mt_time;
    $time_end = mt_get();
    return round($time_end - $mt_time, $len);
}


function logLine($str, $logFile = "default") {
    global $sugar_config;

    if (!endsWith($str, "\n")) {
        $str = $str . "\n";
    }

    if( $logFile == "default") {
        print($str);
    }

// if logging is enabled.
    if (!empty($sugar_config['asterisk_log_file']) && !empty($logFile)) {
        if( $logFile == "default" ) {
            $myFile = $sugar_config['asterisk_log_file'];
        }
        else {
            $myFile = $logFile;
        }
        //  try {
        $fh = fopen($myFile, 'a');
        fwrite($fh, $str);
        fclose($fh);
        //  }
        //  catch(Exception $err) {
        // ignore errors
        //      print "Error: unable to logLine to $myFile: " . $err . '\n';
        //  }
    }
}

// Theoretically safe method, feof will block indefinitely.
function safe_feof($fp, &$start = NULL) {
    $start = microtime(true);
    return feof($fp);
}

function startsWith($haystack, $needle) {
    $length = strlen($needle);
    return (substr($haystack, 0, $length) === $needle);
}

function endsWith($haystack, $needle) {
    $length = strlen($needle);
    $start = $length * -1; //negative
    return (substr($haystack, $start) === $needle);
}

/**
 * Reads all lines from the socket until timeout occurs.
 * @param $socket
 * @param $timeout OPTIONAL (default is 500000 == 1/2 sec)
 * @return string
 */
function AMI_ReadResponse($socket, $timeout = 500000) {
    $retVal = '';
    // Sets timeout to 1/2 a second
    stream_set_timeout($socket, 0, $timeout);
    while (($buffer = fgets($socket, 20)) !== false) {
        $retVal .= $buffer;
    }
    return $retVal;
}

function AMI_WasCmdSuccessful($response) {
    return preg_match('/.*Success.*/s', $response);
}

/**
 * formats the string in a markdown ``` code block indented by 4 spaces
 * @param $str
 * @param $indent - OPTIONAL by default it's " " (4 spaces)
 * @return string
 */
function markdown_indent($str, $indent = " ") {
    $str = preg_replace("/(\r?\n)/i", "$1$indent", $str);
    $str = trim($str);
    $str = "$indent```\n$indent$str\n$indent```";
    return $str;
}

/**
 * AMI event params are not consistent.
 * Dial Events use 'UniqueID'
 * Join and NewCallerID events use 'Uniqueid' and for all we know it might also vary between versions of asterisk
 * So, this method just helps get the Unique ID for the call from the event.
 * @return string set to either $event['UniqueID'], $event['Uniqueid'] or NULL (if neither is set).
 */
function AMI_getUniqueIdFromEvent($event) {
    if (isset($event['UniqueID'])) {
        return $event['UniqueID']; // Dial Event Style, others too maybe
    } else if (isset($event['Uniqueid'])) {
        return $event['Uniqueid']; // Hangup Event Style, others too maybe
    }
    else if (isset($event['UniqueId'])) {
        return $event['UniqueId']; // As far as I know this is never used in AMI added just in case
    }
    return NULL;
}

function AMI_getCallerIdFromEvent($event) {
    if( isset($event['CallerIDNum'] )) {
        return trim($event['CallerIDNum']);
    }
    else if( isset($event['CallerID'] ) ){
        return trim($event['CallerID']);
    }
    else if( isset($event['CallerId'] ) ){
        return trim($event['CallerId']);
    }
    else {
        logLine("__ ERROR: Unable to find caller id in the event! __");
    }
}

function stripExtensionFromAccountCode($channel){
    if(strpos($channel, 'IP') != FALSE){
        $t = explode('/', $channel);
        $temp = explode('-', $t[1]);
        return $temp[0];
    }
}

function was_call_answered($id) {
    $query = "SELECT callstate FROM asterisk_log WHERE asterisk_dest_id='{$id}'";
    $result = db_checked_query($query);
    $result = db_fetchAssoc($result);
    $callstate = $result['callstate'];

    if($callstate == 'Ringing' || $callstate == 'Dial'){
        return 0;
    }else{
        return 1;
    }
}

/**
 * Creates a web link to this record
 * @param $moduleName
 * @param $id
 * @return string
 */
function build_link($moduleName, $id) {
    global $sugar_config;
    if( !empty($moduleName) && !empty($id) ) {
        $moduleName = ucfirst($moduleName);
        return $sugar_config['site_url'] . "/index.php?module=$moduleName&action=DetailView&record={$id}";
    }
    return null;
}

/**
 * Performs an async get request (doesn't wait for response)
 * Note: One limitation of this approach is it will not work if server does any URL rewriting
 */
function gitimg_log($event) {
    $host = "gitimg.com";
    $path = "/rs/track/blak3r/yaai-stats/$event/increment";
    $fp = fsockopen($host,80, $errno, $errstr, 30);
    $out = "GET " . $path . " HTTP/1.1\r\n";
    $out.= "Host: " . $host . "\r\n";
    $out.= "Connection: Close\r\n\r\n";
    fwrite($fp, $out);
    fclose($fp);
}
