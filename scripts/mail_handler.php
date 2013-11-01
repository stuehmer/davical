<?php
ini_set('display_errors', 'On');
error_reporting(E_ALL);

// for config and awl library -
// used to solved by ../htdocs/always.php
// Notice: Undefined index: SERVER_NAME in /home/milan/projects/davical/htdocs/always.php on line 138
// require_once('../htdocs/always.php');

// so copy code from always.php
$try_paths = array(
    '../../awl/inc'
    ,'../../../awl/inc'
, '/usr/share/awl/inc'        // Where it ends up on Debian
, '/usr/share/php/awl/inc'    // Fedora's standard for PHP libraries
, '/usr/local/share/awl/inc'
);
foreach( $try_paths AS $awl_include_path ) {
    if ( @file_exists($awl_include_path.'/AWLUtilities.php') ) {
        echo $awl_include_path;
        set_include_path( $awl_include_path. PATH_SEPARATOR. get_include_path());
        break;
    }
}

require_once('AwlQuery.php');
require_once('vCalendar.php');

require_once('../config/config.php');
require_once('../inc/PlancakeEmailParser.php');
require_once('../inc/Consts.php');

// inspired by :
// http://php.net/manual/en/features.commandline.php
function options ( $args )
{
    array_shift( $args );
    $endofoptions = false;

    $ret = array
    (
        'options' => array(),
    );

    while ( $arg = array_shift($args) )
    {

        // if we have reached end of options,
        //we cast all remaining argvs as arguments
        if ($endofoptions)
        {
            $ret['arguments'][] = $arg;
            continue;
        }

        // Is it a command? (prefixed with --)
        if ( substr( $arg, 0, 2 ) === '--' )
        {

            // is it the end of options flag?
            if (!isset ($arg[3]))
            {
                $endofoptions = true;; // end of options;
                continue;
            }

            $value = "";
            $com   = substr( $arg, 2 );

            // is it the syntax '--option=argument'?
            if (strpos($com,'=')){
                list($com, $value) = explode("=", $com, 2);
            }

            $ret['options'][$com] = !empty($value) ? $value : true;
            continue;
        }

        continue;
    }

    return $ret['options'];
}


class MailInviteHandler {

    public function __construct(){
        dbg_error_log('MailHandler - construct');
    }

    public function sendInvitationToAll(){
        $sql = 'SELECT calendar_item.dav_id as dav_id, calendar_item.user_no as user_no, attendee, dtstamp, dtstart,'
                . 'dtend, summary, uid, email_status,' // .'collection.collection_id,'
                // extra parameters
                . ' location, transp, url, priority, class, calendar_item.description as description, calendar_attendee.partstat as partstat'
                . ' FROM calendar_item'
                . ' INNER JOIN calendar_attendee ON calendar_item.dav_id = calendar_attendee.dav_id'
                //. ' INNER JOIN collection ON collection.user_no = calendar_item.user_no AND collection.is_calendar = TRUE'
                // select calendar_items contained attendee who is remote, have email, and waiting for invitation (email_status=2)
                . ' WHERE attendee LIKE \'mailto:%\' AND is_remote=TRUE AND email_status IN ('
                . EMAIL_STATUS::WAITING_FOR_INVITATION_EMAIL . ','
                . EMAIL_STATUS::WAITING_FOR_SCHEDULE_CHANGE_EMAIL . ')';

        $qry = new AwlQuery($sql);
        $qry->Exec('calendar_items');
        //$rows = $qry->rows();

        while(($row = $qry->Fetch())){
            $currentAttendee = $row->attendee;
            $currentDavID = $row->dav_id;
            //$partstat = $row->partstat;


            $sqlattendee = 'SELECT email as attendee, usr.fullname as params, NULL as partstat, TRUE as creator FROM usr WHERE usr.user_no = :user_no'
                . ' UNION '
                . 'SELECT attendee, params, partstat, FALSE as creator FROM calendar_attendee WHERE calendar_attendee.dav_id = :dav_id'
                . ' ORDER BY creator DESC';

            $qryattendee = new AwlQuery($sqlattendee);
            $qryattendee->Bind(':user_no', $row->user_no);
            $qryattendee->Bind(':dav_id', $currentDavID);
            $qryattendee->Exec('attendess');

            $attendees = array();
            while(($rowattendee = $qryattendee->Fetch())){
                $attendees[] = $rowattendee;
            }


            //dbg_error_log('mail for send invitation:' . $attendees);

            $creator = $attendees[0];
            array_shift($attendees);

            $ctext = $this->renderRowToInvitation($row, $creator, $attendees);


            $invitation = 'Invitation';
            // waiting mail already sent
            if($row->email_status == EMAIL_STATUS::WAITING_FOR_INVITATION_EMAIL){
                $new_status = EMAIL_STATUS::INVITATION_EMAIL_ALREADY_SENT; // invitation mail already sent
            } else if($row->email_status == EMAIL_STATUS::WAITING_FOR_SCHEDULE_CHANGE_EMAIL){
                $invitation .= ' update';
                $new_status = EMAIL_STATUS::SCHEDULE_CHANGE_EMAIL_ALREADY_SENT; // invitation mail already sent
            }

            $dtstart = strtotime($row->dtstart);
            $dtend = strtotime($row->dtend);

            $templatedata = array(
                'summary' => $row->summary,
                'dtstart' => date("m/d/y H:i", $dtstart),
                'dtend' => date("m/d/y H:i", $dtend),
                'creator_name' => $creator->params,
                'creator_email' => $creator->attendee,
                'location' => $creator->location,
                'invitation' => $invitation

            );



            $sent = $this->sendInvitationEmail($currentAttendee, $creator, $ctext, $templatedata);

            if($sent){



                $this->changeRemoteAttendeeStatrusTo($currentAttendee, $currentDavID, $new_status);
            }
        }

    }

    private function changeRemoteAttendeeStatrusTo($attendee, $dav_id, $statusTo){
        $qry = new AwlQuery('UPDATE calendar_attendee SET email_status=:statusTo WHERE attendee=:attendee AND dav_id=:dav_id');
        $qry->Bind(':statusTo', $statusTo);
        $qry->Bind(':attendee', $attendee);
        $qry->Bind(':dav_id', $dav_id);
        $qry->Exec('changeStatusTo');

        return true;
    }

    private function sendInvitationEmail($attendee, $creator, $renderInvitation, $templatedata){

        $filename = 'mail_handler.php.html';
        $fp = fopen($filename, "r");

        $content = fread($fp, filesize($filename));

        //http://webcheatsheet.com/PHP/send_email_text_html_attachment.php

        $title =  $templatedata['invitation']
                    . ': ' . $templatedata['summary']
                    . ' ' . $templatedata['dtstart']
                    . ' - ' . $templatedata['dtend']
                    . ' - ' . $templatedata['creator_name']
                    . ' (' . $templatedata['creator_email'] . ')';



//        $headers .= "MIME-Version: 1.0\n";
//        $headers .= "Content-Type: text/calendar; method=REQUEST;\n";
//        $headers .= '        charset="UTF-8"';
//        $headers .= "\n";
//        $headers .= "Content-Transfer-Encoding: 7bit";

        $attendeeWithoutMailTo = explode('mailto:', $attendee);
        if(count($attendeeWithoutMailTo) > 1){
            $attendee = $attendeeWithoutMailTo[1];
        }

        $random_hash = md5(date('r', time()));
        //define the headers we want passed. Note that they are separated with \r\n

        $headers = "From: webmaster@example.com\r\nReply-To: webmaster@example.com";
        $headers = sprintf("From: %s\r\nReply-To: %s", $creator->params, $creator->attendee);
        //add boundary string and mime type specification
        $headers .= "\r\nContent-Type: multipart/mixed; boundary=\"PHP-mixed-".$random_hash."\"";
        //read the atachment file contents into a string,
        //encode it with MIME base64,
        //and split it into smaller chunks
        $attachment = chunk_split(base64_encode($renderInvitation));

        $content = str_replace("[[RANDOM-HASH]]", $random_hash, $content);
        $content = str_replace("[[ATTACHMENT]]", $attachment, $content);
        $content = str_replace("[[SUBJECT]]", $title, $content);

        $content = str_replace("[[SUMMARY]]", $templatedata['summary'], $content);
        $content = str_replace("[[CREATOR]]", $templatedata['creator_name'], $content);
        $content = str_replace("[[EMAIL]]", $templatedata['creator_email'], $content);
        $content = str_replace("[[DTSTART]]", $templatedata['dtstart'], $content);
        $content = str_replace("[[DTEND]]", $templatedata['dtend'], $content);
        $content = str_replace("[[LOCATION]]", $templatedata['location'], $content);


        $result = mail($attendee, $title, $content, $headers);
//            if($result){
//              return true;
//            }

        return true;
    }

    /**
     * @param $row - array with :
     * // minimum for invitation http://www.ietf.org/rfc/rfc5546.txt [Page 20]
     *  SUMMARY
     *  DTSTAMP
     *  DTSTART
     *  DTEND
     *  UID
     *
     * // extra params:
     *
     *
     * @param $organizer - array of organizer contain email as attendee and fullname as property column
     * @param $attendees - array of arrays with attendees (attendee, property)
     * @return string
     */
    private function renderRowToInvitation($row, $organizer, $attendees){

        $status='TENTATIVE';

        $calendar = new vCalendar();
        $calendar->AddProperty("METHOD", "REQUEST");


        $event = new vComponent();
        $event->SetType("VEVENT");



        $event->AddProperty("SUMMARY", $row->summary);
        $event->AddProperty("DTSTAMP", $row->dtstamp);
        $event->AddProperty("DTSTART", $row->dtstart);
        $event->AddProperty("DTEND", $row->dtend);
        $event->AddProperty("UID", $row->uid);

        $event->AddProperty("EMAIL", $organizer->attendee);
        $event->AddProperty("LOCATION", $organizer->location);

        // url
        //$event->AddProperty("URL", "http://127.0.0.1/public.php?XDEBUG_SESSION_START=14830");



        $organizerproperty = null;
        if(isset($organizer->params) && $organizer->params != null) {
            $organizerproperty = array( 'CN' => $organizer->params);
        }

        $event->AddProperty("ORGANIZER", 'mailto:'. $organizer->attendee, $organizerproperty);

        $event->AddProperty("STATUS", $status);


        foreach($attendees as $attendee){
            $partstat = $attendee->partstat;

            $attendeePropertyArray = $this->extractParametersToArrayFromProperty($attendee->params);
            // add partstat from DB
            $attendeePropertyArray['PARTSTAT'] = $partstat;

            $event->AddProperty("ATTENDEE", $attendee->attendee, $attendeePropertyArray );
        }


        $calendar->AddComponent($event);

        $result = $calendar->render();


        return $result;
    }

    /**
     * @param $attendeeproperty - is not iCalendar property in string with param and name
     * @return array|null
     */
    private function extractParametersToArrayFromProperty(&$attendeeproperty){
        $parameters = null;
        if(isset($attendeeproperty) && $attendeeproperty != null) {
            // after symbol ":" -> value what we are not interest
            $superProp = $attendeeproperty;

            if(count($superProp) > 0){
                $superProp = $superProp[0];
            } else {
                $superProp = &$attendeeproperty;
            }

            // explore parameters dividet by ";"
            $propertyInArray = explode(';', $superProp);

            // first is name of property -> no params
            if(count($propertyInArray) > 1){
                array_shift($propertyInArray);

                $parameters = array();
                foreach($propertyInArray as $property){
                    $keyproperty = explode('=', $property);
                    $key = strtoupper($keyproperty[0]);

                    if(count($keyproperty) > 1){
                        $parameters[$key] = $keyproperty[1];
                    } else {
                        $parameters[$key] = '';
                    }

                }
            }

        }

        return $parameters;
    }

    public function handleIncomingMail($emailBuffer){
        $pep = new PlancakeEmailParser($emailBuffer);

        $body = $pep->getBody();
        if(empty($body) || !$body){
            $body = $pep->getHtmlBody();
        }

        $vcalendarStart = strpos($body, "BEGIN:VCALENDAR");
        $vcalendarEnd = strpos($body, "END:VCALENDAR", $vcalendarStart);

        $vcalendarBody = substr($body, $vcalendarStart, $vcalendarEnd - $vcalendarStart);


        echo "subject: " . $pep->getSubject() . "\r\n";
        echo "to:" . $pep->getTo()[0] . "\r\n";
        echo "body: " . $body . "\r\n";
        echo "vcalendarBody: " . $vcalendarBody . "\r\n";

        $ical = new vCalendar($vcalendarBody);
        $this->handle_remote_attendee_reply($ical);
    }


    function handle_remote_attendee_reply(vCalendar $ical){
        $attendees = $ical->GetAttendees();

        // attendee reply have just one attendee
        if(count($attendees) != 1){
            return;
        }

        $attendee = $attendees[0];
        $uidparam =  $ical->GetPropertiesByPath("VCALENDAR/*/UID");
        $uid = $uidparam[0]->Value();

        $parameters = $attendee->Parameters();

        $propertyText = '';

        foreach($parameters as $key => $param){

            if(!empty($propertyText)){
                $propertyText .= ';';
            }

            $propertyText .= $key . '=' . $param;
        }

        //$propertyText .= ':' . $attendee->Value();

        $qry = new AwlQuery('SELECT dav_id, calendar_item.collection_id AS collection_id, calendar_item.dav_name AS dav_name, caldav_data FROM calendar_item LEFT JOIN caldav_data USING(dav_id) WHERE uid = :uid');
        $qry->Bind(':uid', $uid);
        $qry->Exec('select dav_id, collection_id');
        if(($row = $qry->Fetch())){
            $qry = new AwlQuery('UPDATE calendar_attendee SET email_status=:statusTo, partstat=:partstat, params=:params WHERE attendee=:attendee AND dav_id = :dav_id');
            // user accepted
            $qry->Bind(':statusTo', EMAIL_STATUS::NORMAL);
            $qry->Bind(':attendee', $attendee->Value());
            $qry->Bind(':dav_id', $row->dav_id);
            $qry->Bind(':params', $propertyText);
            $qry->Bind(':partstat', $parameters['PARTSTAT']);

            $qry->Exec('changeStatusTo');

            //'(SELECT dav_id FROM calendar_item WHERE uid = :uid)';

            $collection_id = $row->collection_id;
            $dav_name = $row->dav_name;
            //$qry->QDo("SELECT write_sync_change( $collection_id, 200, :dav_name)", array(':dav_name' => $dav_name ) );
            //$qry->Execute();


            $this->update_caldav_data($row->caldav_data, $row->dav_id);
        }

        return true;
    }


    function update_caldav_data($old_data, $dav_id){
        $vResource = new vComponent($old_data);

        //$expanded = expand_event_instances($vResource, $expand_range_start, $expand_range_end);

        $event = $vResource->GetComponents("VEVENT")[0];

        $attendeeName = "ATTENDEE";

        $vResource->ClearProperties($attendeeName);

        $davIdArray = array(':dav_id' => $dav_id);

        $attendeeQry = new AwlQuery("SELECT params, attendee FROM calendar_attendee WHERE dav_id = :dav_id", $davIdArray);
        $attendeeQry->Execute();



        while(($arow = $attendeeQry->Fetch())){
            $attendeeParameters = $arow->params;
            $attendeeValue = $arow->attendee;
            // separe value
            $event->AddProperty($attendeeName, $attendeeValue, $attendeeParameters);
        }

        $rendered = $vResource->Render();

        $sql = 'UPDATE caldav_data SET caldav_data=:dav_data, dav_etag=:etag WHERE dav_id=:dav_id';

        $davIdArray[':etag'] = md5($rendered);
        $davIdArray[':dav_data'] = $rendered;

        $query = new AwlQuery($sql, $davIdArray);
        $query->Execute();

    }

}


$options = options($argv);
//var_dump($options);


if(count($options) > 0){
    $mailHandler = new MailInviteHandler();

    if( isset($options['fmail'])){
        // is presed fmail option?
        // eg: --fmail=/home/email/invitation_reply_1.eml
        $file = fopen($options['fmail'], 'r');
        $mailHandler->handleIncomingMail(stream_get_contents($file));
        fclose($file);
    } else if(isset($options['stdin']) && $options['stdin']) {
        // or presed stdin flag eg: --stdin or --stdin=true
        $mailHandler->handleIncomingMail(stream_get_contents(STDIN));
    }

    // or presed stdin flag eg: --stdin or --stdin=true
    if(isset($options['invite-all']) && $options['invite-all']){
        $mailHandler->sendInvitationToAll();
    }

}






